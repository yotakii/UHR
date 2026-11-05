<?php

namespace App\Services\Space;

use App\Models\Celebration;
use App\Models\Community;
use App\Models\Employee;
use App\Models\SpacePost;
use App\Models\User;
use App\Support\Notify;
use App\Support\Settings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * الاحتفالات التلقائية (12b): منشور نظام في مجتمع الشركة — أعياد الميلاد (اليوم والشهر فقط، ولمن لم يخفِ تاريخه)،
 * سنويات الخدمة، المنضمون الجدد، والترقيات (عند تغيير المسمى مع خيار الإعلان، والنقل الداخلي). مرة واحدة لكل مفتاح.
 */
class Celebrations
{
    public const KINDS = ['birthday', 'anniversary', 'joiner', 'promotion'];

    public function __construct(private Space $space) {}

    public static function config(): array { return Settings::merged('celebrations'); }

    private function on(string $setting): bool
    {
        $c = self::config();
        return Space::config()['enabled'] && $c['enabled'] && $c[$setting];
    }

    /** كل احتفالات اليوم في الشركة الحالية — يعيد عدد المنشورات الجديدة */
    public function run(?Carbon $day = null): int
    {
        $day = ($day ?? now())->copy()->startOfDay();
        $md = $day->format('m-d');
        $mds = $md === '02-28' && ! $day->isLeapYear() ? ['02-28', '02-29'] : [$md];   // مواليد 29 شباط في السنوات غير الكبيسة
        $base = fn () => Employee::current()->where('status', '!=', 'onboarding')->orderBy('name');
        $n = 0;
        if ($this->on('birthdays')) {
            $n += $this->post('birthday', $day, $base()->where('hide_birthday', false)->whereNotNull('dob')->whereIn(DB::raw("to_char(dob, 'MM-DD')"), $mds)->get());
        }
        if ($this->on('anniversaries')) {
            $n += $this->post('anniversary', $day, $base()->whereIn(DB::raw("to_char(hire_date, 'MM-DD')"), $mds)->whereYear('hire_date', '<', $day->year)->get());
        }
        if ($this->on('new_joiners')) {
            $n += $this->post('joiner', $day, Employee::current()->whereDate('hire_date', $day->toDateString())->orderBy('name')->get());
        }
        return $n;
    }

    /** ترقية/مسمى جديد لموظف — يُستدعى عند تغيير المسمى بخيار الإعلان، وعند النقل الداخلي */
    public function promotion(Employee $e, string $oldTitle): ?SpacePost
    {
        if (! $this->on('promotions') || $e->status === 'terminated') return null;
        $day = now()->startOfDay();
        $post = null;
        $this->post('promotion', $day, collect([$e]), "promotion:{$e->id}:".$day->toDateString(), ['from' => $oldTitle], $post);
        return $post;
    }

    public static function years(int $n): string
    {
        return match (true) { $n === 1 => 'سنة واحدة', $n === 2 => 'سنتين', $n <= 10 => "{$n} سنوات", default => "{$n} سنة" };
    }

    private function person(Employee $e, Carbon $day, ?int $uid): array
    {
        return ['employee_id' => $e->id, 'user_id' => $uid, 'name' => ['ar' => $e->name, 'en' => $e->name_en ?: $e->name], 'title' => ['ar' => $e->title_ar, 'en' => $e->title_en],
            'gender' => $e->gender, 'years' => $e->hire_date ? (int) $e->hire_date->diffInYears($day) : null];
    }

    private function post(string $kind, Carbon $day, Collection $emps, ?string $key = null, array $extra = [], ?SpacePost &$out = null): int
    {
        if ($emps->isEmpty()) return 0;
        $key ??= "{$kind}:".$day->toDateString();
        if (Celebration::where('key', $key)->exists()) return 0;
        $this->space->ensureDefaults();
        $community = Community::where('kind', 'company')->first();
        if (! $community) return 0;
        $users = User::where('active', true)->whereIn('employee_id', $emps->pluck('id'))->pluck('id', 'employee_id');
        $people = $emps->map(fn ($e) => $this->person($e, $day, $users[$e->id] ?? null))->values()->all();
        $names = implode('، ', array_map(fn ($p) => $p['name']['ar'], $people));
        $body = match ($kind) {
            'birthday' => "عيد ميلاد سعيد لزملائنا: {$names}. كل عام وأنتم بخير!",
            'anniversary' => 'نحتفل اليوم بسنوية الخدمة لزملائنا: '.implode('، ', array_map(fn ($p) => $p['name']['ar'].' ('.self::years($p['years']).')', $people)).'. شكراً لعطائكم!',
            'joiner' => "مرحباً بزملائنا الجدد: {$names}. نتمنى لكم بداية موفقة!",
            'promotion' => "تهانينا لـ{$names} بالمسمى الجديد: {$people[0]['title']['ar']}.",
        };
        $post = DB::transaction(function () use ($community, $kind, $body, $people, $extra, $key, $day, $emps) {
            $post = SpacePost::create(['community_id' => $community->id, 'user_id' => null, 'kind' => 'celebration', 'body' => $body, 'meta' => ['type' => $kind, 'people' => $people] + $extra]);
            Celebration::create(['kind' => $kind, 'on' => $day->toDateString(), 'key' => $key, 'employees' => $emps->pluck('id')->all(), 'space_post_id' => $post->id]);
            return $post;
        });
        $out = $post;
        foreach ($people as $p) {
            if (! $p['user_id']) continue;
            [$ar, $en] = match ($kind) {
                'birthday' => ['كل عام وأنت بخير! زملاؤك يهنّئونك بعيد ميلادك في الساحة', 'Happy birthday! Your colleagues are celebrating you in the Space'],
                'anniversary' => ['نحتفل بإتمامك '.self::years($p['years']).' في الشركة — شكراً لعطائك', "Celebrating your {$p['years']} years with us — thank you"],
                'joiner' => ['أهلاً بك! أعلنّا انضمامك للزملاء في الساحة', 'Welcome! We announced your arrival in the Space'],
                'promotion' => ['تهانينا بالمسمى الجديد! أعلنّاه للزملاء في الساحة', 'Congratulations on your new title! We announced it in the Space'],
            };
            Notify::user($p['user_id'], $ar, $en, 'space:'.$post->id);
        }
        return 1;
    }
}
