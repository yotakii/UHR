<?php

namespace App\Services\People;

use App\Exceptions\ApiException;
use App\Models\Department;
use App\Models\Location;
use App\Models\User;
use App\Support\Audit;
use App\Support\L;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Writer;

/**
 * استيراد الموظفين الحاليين من Excel (M06 — SRS §6 M06، §9.2): قالب قابل للتنزيل، معاينة بأخطاء كل سطر،
 * والحفظ لا يتم إلا لملف بلا أخطاء (كله أو لا شيء). الأعمدة تُعرف بالمفتاح قبل « — » في عنوانها.
 */
class EmployeeImport
{
    public const MAX_ROWS = 2000;

    /** key => [عنوان عربي، مثال] — * إلزامي */
    public const COLUMNS = [
        'code' => ['الرقم الوظيفي (فارغ = تلقائي)', 'UHR-1001'], 'name' => ['الاسم الكامل*', 'سامر الحلبي'], 'name_en' => ['الاسم بالإنكليزية*', 'Samer Al-Halabi'],
        'gender' => ['الجنس* M/F', 'M'], 'national_id' => ['الرقم الوطني', '01020304050'], 'dob' => ['تاريخ الولادة YYYY-MM-DD', '1990-05-14'],
        'phone' => ['الجوال', '+963 944 000 000'], 'email' => ['البريد*', 'samer.halabi@company.sy'], 'department' => ['رمز الإدارة*', 'it'],
        'title_ar' => ['المسمى الوظيفي*', 'مطور برمجيات'], 'title_en' => ['المسمى بالإنكليزية*', 'Software developer'], 'location' => ['رمز مقر العمل*', 'dam'],
        'manager_code' => ['الرقم الوظيفي للمدير', ''], 'salary' => ['الراتب الشامل*', '900'], 'currency' => ['العملة USD/SYP/EUR', 'USD'],
        'hire_date' => ['تاريخ المباشرة* YYYY-MM-DD', '2022-03-01'], 'nfc_uid' => ['رقم بطاقة الحضور NFC', ''],
    ];

    public function __construct(private NewEmployee $factory) {}

    /** القالب: ورقة الموظفين بسطر مثال + ورقة رموز الإدارات والمقرات */
    public function template(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'uhr').'.xlsx';
        $w = new Writer(); $w->openToFile($path);
        $bold = (new Style())->setFontBold()->setBackgroundColor('E8F2EE');
        $sheet = $w->getCurrentSheet(); $sheet->setName('employees')->setSheetView((new SheetView())->setRightToLeft(true)->setFreezeRow(2));
        $sheet->setColumnWidthForRange(22, 1, count(self::COLUMNS));
        $w->addRow(Row::fromValues(array_map(fn ($k, $c) => "{$k} — {$c[0]}", array_keys(self::COLUMNS), self::COLUMNS), $bold));
        $w->addRow(Row::fromValues(array_column(self::COLUMNS, 1)));
        $w->addNewSheetAndMakeItCurrent()->setName('codes')->setSheetView((new SheetView())->setRightToLeft(true));
        $w->getCurrentSheet()->setColumnWidthForRange(28, 1, 6);
        $w->addRow(Row::fromValues(['department', 'الإدارة', 'Department', '', 'location', 'المقر'], $bold));
        $deps = Department::where('active', true)->orderBy('sort')->get(); $locs = Location::where('active', true)->orderBy('id')->get();
        for ($i = 0; $i < max($deps->count(), $locs->count()); $i++) {
            $d = $deps[$i] ?? null; $l = $locs[$i] ?? null;
            $w->addRow(Row::fromValues([$d?->code ?? '', $d?->name_ar ?? '', $d?->name_en ?? '', '', $l?->code ?? '', $l?->name_ar ?? '']));
        }
        $w->close();
        return $path;
    }

    /** @return list<array{row: int, values: array, errors: array<string,string>}> */
    public function preview(UploadedFile $file): array
    {
        $raw = $this->read($file);
        $seen = ['emails' => [], 'ids' => [], 'codes' => []]; $out = [];
        foreach ($raw as $n => $r) {
            [$v, $err] = NewEmployee::check($r, true, $seen);
            $seen['emails'][] = $v['email']; if ($v['national_id']) $seen['ids'][] = $v['national_id']; if ($v['code']) $seen['codes'][] = $v['code'];
            $out[] = ['row' => $n, 'values' => $v, 'raw' => $r, 'errors' => $err];
        }
        return $out;
    }

    /** الحفظ: يعيد المعاينة، ولا يحفظ شيئاً إن وُجد خطأ واحد */
    public function commit(UploadedFile $file, bool $invite, User $by): array
    {
        $rows = $this->preview($file);
        $bad = array_filter($rows, fn ($r) => $r['errors']);
        if ($bad) throw new ApiException(L::t('صحّح الأسطر التي فيها أخطاء ثم أعد الرفع.', 'Fix the rows with errors and upload again.'), 'import_errors', 422);
        $codes = [];
        $made = DB::transaction(function () use ($rows, $invite, $by, &$codes) {
            $out = [];
            foreach ($rows as $r) $out[] = $this->factory->import($r['values'], $invite, $by, $codes);
            return $out;
        });
        Audit::record('employees.imported', null, null, ['count' => count($made), 'codes' => array_map(fn ($e) => $e->code, $made), 'invite' => $invite]);
        return $made;
    }

    /** يقرأ أول ورقة: سطر العناوين ثم البيانات (يتجاهل سطر المثال والأسطر الفارغة) */
    private function read(UploadedFile $file): array
    {
        $ext = strtolower($file->getClientOriginalExtension());
        if (! in_array($ext, ['xlsx', 'csv'], true)) throw ApiException::field('file', L::t('ارفع ملف Excel بصيغة xlsx أو csv.', 'Upload an Excel file (xlsx or csv).'), 'bad_file');
        $reader = $ext === 'csv' ? new CsvReader() : new XlsxReader();
        try { $reader->open($file->getRealPath()); } catch (\Throwable) { throw ApiException::field('file', L::t('تعذّرت قراءة الملف.', 'The file could not be read.'), 'bad_file'); }
        $keys = null; $rows = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $i => $row) {
                $cells = array_map(fn ($c) => is_string($c) ? trim($c) : $c, $row->toArray());
                if ($keys === null) {
                    $keys = array_map(fn ($h) => strtolower(trim(explode(' — ', (string) $h)[0])), $cells);
                    if (! in_array('email', $keys, true) || ! in_array('name', $keys, true)) { $reader->close(); throw ApiException::field('file', L::t('الملف لا يطابق القالب — نزّل القالب واملأه.', 'The file does not match the template — download the template and fill it.'), 'bad_template'); }
                    continue;
                }
                if (! array_filter($cells, fn ($c) => $c !== '' && $c !== null)) continue;
                $r = []; foreach ($keys as $j => $k) if (isset(self::COLUMNS[$k])) $r[$k] = $cells[$j] ?? '';
                if (($r['email'] ?? '') === self::COLUMNS['email'][1]) continue;   // سطر المثال
                $rows[$i] = $r;
                if (count($rows) > self::MAX_ROWS) { $reader->close(); throw ApiException::field('file', L::t('الحد الأقصى '.self::MAX_ROWS.' سطر في الملف الواحد.', 'At most '.self::MAX_ROWS.' rows per file.'), 'too_many_rows'); }
            }
            break;
        }
        $reader->close();
        if (! $rows) throw ApiException::field('file', L::t('الملف فارغ — أضف الموظفين تحت سطر العناوين.', 'The file is empty — add employees under the header row.'), 'empty_file');
        return $rows;
    }
}
