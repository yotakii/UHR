<?php

namespace App\Services\Assets;

use App\Exceptions\ApiException;
use App\Models\Asset;
use App\Models\AssetCategory;
use App\Models\Employee;
use App\Models\User;
use App\Support\Audit;
use App\Support\Counters;
use App\Support\L;
use App\Support\Notify;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Writer;

/**
 * استيراد العهد من Excel (D-13): قالب رسمي ← رفع ← تحقق الأعمدة والحقول ومطابقة الموظفين والفئات وكشف التكرار ← معاينة ← تأكيد.
 * الصف الخاطئ يُستثنى ويُدرج بتقرير الأخطاء، والباقي يُستورد (100 صف فيها 3 أخطاء ← 97 + تقرير)، ويُسجَّل من استورد ومتى وكم نجح وفشل.
 * الأصل المسلّم لموظف يصبح «بانتظار التوقيع» — أو «مسلّماً» مباشرة إن كان موقّعاً ورقياً من قبل.
 */
class AssetImport
{
    public const MAX_ROWS = 2000;

    /** key => [عنوان، مثال] — * إلزامي */
    public const COLUMNS = [
        'tag' => ['رمز الأصل (فارغ = تلقائي)', ''], 'name_ar' => ['اسم الأصل*', 'حاسب محمول Dell Latitude 5440'], 'name_en' => ['الاسم بالإنكليزية*', 'Dell Latitude 5440 laptop'],
        'category' => ['رمز الفئة*', 'computers'], 'serial' => ['الرقم التسلسلي', 'SN12345678'], 'value' => ['القيمة التقديرية*', '950'],
        'purchased_at' => ['تاريخ الشراء YYYY-MM-DD', '2025-01-15'], 'condition' => ['الحالة good/fair/damaged', 'good'],
        'employee_code' => ['الرقم الوظيفي للحائز (فارغ = مستودع)', ''], 'notes' => ['ملاحظات', ''],
    ];

    public function template(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'uhr').'.xlsx';
        $w = new Writer(); $w->openToFile($path);
        $bold = (new Style())->setFontBold()->setBackgroundColor('E8F2EE');
        $sheet = $w->getCurrentSheet(); $sheet->setName('assets')->setSheetView((new SheetView())->setRightToLeft(true)->setFreezeRow(2));
        $sheet->setColumnWidthForRange(24, 1, count(self::COLUMNS));
        $w->addRow(Row::fromValues(array_map(fn ($k, $c) => "{$k} — {$c[0]}", array_keys(self::COLUMNS), self::COLUMNS), $bold));
        $w->addRow(Row::fromValues(array_column(self::COLUMNS, 1)));
        $w->addNewSheetAndMakeItCurrent()->setName('codes')->setSheetView((new SheetView())->setRightToLeft(true));
        $w->getCurrentSheet()->setColumnWidthForRange(26, 1, 3);
        $w->addRow(Row::fromValues(['category', 'الفئة', 'Category'], $bold));
        foreach (AssetCategory::orderBy('id')->get() as $c) $w->addRow(Row::fromValues([$c->code, $c->name_ar, $c->name_en]));
        $w->close();
        return $path;
    }

    /** @return list<array{row: int, values: array, errors: array<string,string>}> */
    public function preview(UploadedFile $file): array
    {
        $raw = $this->read($file);
        $cats = AssetCategory::pluck('id', 'code')->mapWithKeys(fn ($id, $code) => [mb_strtolower($code) => $id]);
        $emps = Employee::current()->pluck('id', 'code')->mapWithKeys(fn ($id, $code) => [mb_strtoupper($code) => $id]);
        $tags = Asset::pluck('tag')->map(fn ($t) => mb_strtoupper($t))->flip();
        $serials = Asset::whereNotNull('serial')->pluck('serial')->map(fn ($s) => mb_strtoupper($s))->flip();
        $seenTags = []; $seenSerials = []; $out = [];
        foreach ($raw as $n => $r) {
            $e = [];
            $v = ['tag' => mb_strtoupper(trim((string) ($r['tag'] ?? ''))), 'name_ar' => trim((string) ($r['name_ar'] ?? '')), 'name_en' => trim((string) ($r['name_en'] ?? '')),
                'category' => mb_strtolower(trim((string) ($r['category'] ?? ''))), 'serial' => trim((string) ($r['serial'] ?? '')), 'value' => $r['value'] ?? '',
                'purchased_at' => $this->date($r['purchased_at'] ?? ''), 'condition' => mb_strtolower(trim((string) ($r['condition'] ?? ''))) ?: 'good',
                'employee_code' => mb_strtoupper(trim((string) ($r['employee_code'] ?? ''))), 'notes' => trim((string) ($r['notes'] ?? ''))];
            if ($v['name_ar'] === '') $e['name_ar'] = L::t('اسم الأصل إلزامي', 'Asset name is required');
            if ($v['name_en'] === '') $e['name_en'] = L::t('الاسم بالإنكليزية إلزامي', 'English name is required');
            if (! isset($cats[$v['category']])) $e['category'] = L::t('رمز الفئة غير موجود — راجع ورقة codes', 'Unknown category code — see the codes sheet');
            if (! is_numeric($v['value']) || (float) $v['value'] < 0) $e['value'] = L::t('القيمة رقم موجب', 'Value must be a positive number');
            if (($r['purchased_at'] ?? '') !== '' && ($r['purchased_at'] ?? null) !== null && ! $v['purchased_at']) $e['purchased_at'] = L::t('التاريخ بصيغة YYYY-MM-DD', 'Date must be YYYY-MM-DD');
            if (! in_array($v['condition'], Asset::CONDITIONS, true)) $e['condition'] = L::t('الحالة good أو fair أو damaged', 'Condition is good, fair or damaged');
            if ($v['employee_code'] !== '' && ! isset($emps[$v['employee_code']])) $e['employee_code'] = L::t('لا يوجد موظف حالي بهذا الرقم', 'No current employee with this code');
            if ($v['employee_code'] !== '' && $v['condition'] === 'damaged') $e['condition'] = L::t('لا يُسلَّم أصل تالف', 'A damaged asset cannot be issued');
            if ($v['tag'] !== '') {
                if (! preg_match('/^[A-Z0-9][A-Z0-9-]{1,19}$/', $v['tag'])) $e['tag'] = L::t('رمز غير صالح', 'Invalid tag');
                elseif (isset($tags[$v['tag']])) $e['tag'] = L::t('الرمز مسجّل مسبقاً', 'This tag already exists');
                elseif (isset($seenTags[$v['tag']])) $e['tag'] = L::t('مكرر في الملف (سطر '.$seenTags[$v['tag']].')', 'Duplicate in the file (row '.$seenTags[$v['tag']].')');
                $seenTags[$v['tag']] ??= $n;
            }
            if ($v['serial'] !== '') {
                $s = mb_strtoupper($v['serial']);
                if (isset($serials[$s])) $e['serial'] = L::t('الرقم التسلسلي مسجّل لأصل آخر', 'Serial already belongs to another asset');
                elseif (isset($seenSerials[$s])) $e['serial'] = L::t('مكرر في الملف (سطر '.$seenSerials[$s].')', 'Duplicate in the file (row '.$seenSerials[$s].')');
                $seenSerials[$s] ??= $n;
            }
            $v['category_id'] = $cats[$v['category']] ?? null; $v['employee_id'] = $emps[$v['employee_code']] ?? null;
            $out[] = ['row' => $n, 'values' => $v, 'errors' => $e];
        }
        return $out;
    }

    /** يستورد الصفوف السليمة ويعيد تقرير الصفوف المستثناة */
    public function commit(UploadedFile $file, bool $paperSigned, User $by): array
    {
        $rows = $this->preview($file);
        $good = array_values(array_filter($rows, fn ($r) => ! $r['errors']));
        $bad = array_values(array_filter($rows, fn ($r) => $r['errors']));
        $holders = [];
        DB::transaction(function () use ($good, $paperSigned, $by, &$holders) {
            foreach ($good as $r) {
                $v = $r['values'];
                $tag = $v['tag'] ?: Counters::assetTag();
                $held = (bool) $v['employee_id'];
                $a = Asset::create(['tag' => $tag, 'name_ar' => $v['name_ar'], 'name_en' => $v['name_en'], 'asset_category_id' => $v['category_id'], 'serial' => $v['serial'] ?: null,
                    'value' => round((float) $v['value'], 2), 'purchased_at' => $v['purchased_at'], 'condition' => $v['condition'], 'notes' => $v['notes'] ?: null,
                    'employee_id' => $v['employee_id'], 'status' => $held ? ($paperSigned ? 'assigned' : 'pending_ack') : ($v['condition'] === 'damaged' ? 'maintenance' : 'available'),
                    'assigned_at' => $held ? now() : null, 'signed_at' => $held && $paperSigned ? now() : null]);
                Custody::move($a, 'imported', $v['employee_id'], $by, $held && $paperSigned ? 'موقّعة ورقياً' : null, $v['condition']);
                if ($held) $holders[$v['employee_id']][] = $a;
            }
            // أصل الرمز الصريح يرفع العدّاد حتى لا يتكرر الرمز التلقائي لاحقاً
            $max = Asset::pluck('tag')->map(fn ($t) => (int) preg_replace('/\D/', '', $t))->max();
            if ($max) Counters::atLeast('asset', $max);
        });
        foreach ($holders as $empId => $list) {
            $e = Employee::find($empId);
            $names = collect($list)->pluck('name_ar')->implode('، '); $en = collect($list)->pluck('name_en')->implode(', ');
            $e->log("استيراد عهد: {$names}".($paperSigned ? ' (موقّعة ورقياً)' : ' — بانتظار التوقيع'), "Custody imported: {$en}".($paperSigned ? ' (signed on paper)' : ' — awaiting signature'));
            if (! $paperSigned) Notify::user($e->user()->value('id'), 'سُلّمت لك '.count($list).' عهدة — وقّع سندات الاستلام', count($list).' asset(s) issued to you — please sign the receipts', 'docs');
        }
        Audit::record('assets.imported', null, null, ['ok' => count($good), 'failed' => count($bad), 'paper_signed' => $paperSigned, 'file' => $file->getClientOriginalName()]);
        return ['created' => count($good), 'failed' => count($bad), 'errors' => array_map(fn ($r) => ['row' => $r['row'], 'values' => $r['values'], 'errors' => $r['errors']], $bad)];
    }

    private function date(mixed $v): ?string
    {
        if ($v instanceof \DateTimeInterface) return $v->format('Y-m-d');
        $s = trim((string) $v);
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) && strtotime($s) ? $s : null;
    }

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
                    if (! in_array('name_ar', $keys, true) || ! in_array('category', $keys, true)) { $reader->close(); throw ApiException::field('file', L::t('الملف لا يطابق القالب — نزّل القالب واملأه.', 'The file does not match the template — download the template and fill it.'), 'bad_template'); }
                    continue;
                }
                if (! array_filter($cells, fn ($c) => $c !== '' && $c !== null)) continue;
                $r = []; foreach ($keys as $j => $k) if (isset(self::COLUMNS[$k])) $r[$k] = $cells[$j] ?? '';
                if (($r['name_ar'] ?? '') === self::COLUMNS['name_ar'][1] && ($r['serial'] ?? '') === self::COLUMNS['serial'][1]) continue;   // سطر المثال
                $rows[$i] = $r;
                if (count($rows) > self::MAX_ROWS) { $reader->close(); throw ApiException::field('file', L::t('الحد الأقصى '.self::MAX_ROWS.' سطر في الملف الواحد.', 'At most '.self::MAX_ROWS.' rows per file.'), 'too_many_rows'); }
            }
            break;
        }
        $reader->close();
        if (! $rows) throw ApiException::field('file', L::t('الملف فارغ — أضف الأصول تحت سطر العناوين.', 'The file is empty — add assets under the header row.'), 'empty_file');
        return $rows;
    }
}
