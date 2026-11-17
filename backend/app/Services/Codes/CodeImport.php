<?php

namespace App\Services\Codes;

use App\Exceptions\ApiException;
use App\Models\CodeItem;
use App\Models\CodeList;
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
 * استيراد وتصدير Excel لكل قائمة (FR-CS-009): الأعمدة بالمفتاح قبل « — »، معاينة بأخطاء كل سطر
 * (النمط، الطول، التكرار في الملف وفي الدليل شاملاً المعطل، وجود الأب)، والحفظ كله أو لا شيء.
 */
class CodeImport
{
    public const MAX_ROWS = 2000;
    private const BASE = ['code' => 'الرمز*', 'name_ar' => 'الاسم بالعربية*', 'name_en' => 'الاسم بالإنكليزية*', 'parent_code' => 'رمز الأب', 'valid_from' => 'بداية السريان YYYY-MM-DD',
        'valid_to' => 'نهاية السريان', 'legacy_code' => 'الرمز السابق', 'sort' => 'الترتيب', 'description' => 'الوصف'];

    private static function columns(CodeList $l): array
    {
        $cols = self::BASE;
        foreach ($l->attrs_schema ?? [] as $f) $cols['attr_'.$f['key']] = $f['ar'].($f['type'] === 'bool' ? ' (1/0)' : ($f['type'] === 'select' ? ' ('.implode('/', $f['options']).')' : ''));
        return $cols;
    }

    /** ملف القائمة كما هي (تصدير) أو قالب فارغ بسطر مثال */
    public static function file(CodeList $l, bool $template): string
    {
        $path = tempnam(sys_get_temp_dir(), 'cs').'.xlsx';
        $w = new Writer(); $w->openToFile($path);
        $w->getCurrentSheet()->setName(substr($l->key, 0, 31))->setSheetView((new SheetView())->setRightToLeft(true)->setFreezeRow(1));
        $cols = self::columns($l);
        $w->getCurrentSheet()->setColumnWidthForRange(22, 1, count($cols));
        $w->addRow(Row::fromValues(array_map(fn ($k, $t) => "{$k} — {$t}", array_keys($cols), $cols), (new Style())->setFontBold()->setBackgroundColor('E8F2EE')));
        $rows = $template ? [] : Codes::rows($l);
        $codes = CodeItem::withoutGlobalScopes()->pluck('code', 'id');
        if ($template) $w->addRow(Row::fromValues([$l->example ?: 'X01', 'مثال', 'Example']));
        foreach ($rows as $r) {
            $vals = [$r['code'], $r['ar'], $r['en'], $r['parent_id'] ? ($codes[$r['parent_id']] ?? '') : '', $r['valid_from'] ?? '', $r['valid_to'] ?? '', $r['legacy_code'] ?? '', $r['sort'], $r['description'] ?? ''];
            foreach ($l->attrs_schema ?? [] as $f) { $x = $r['attrs'][$f['key']] ?? ''; $vals[] = is_bool($x) ? ($x ? 1 : 0) : $x; }
            $w->addRow(Row::fromValues($vals));
        }
        $w->close();
        return $path;
    }

    /** المعاينة: لكل سطر القيم المطبّعة وأخطاؤه */
    public static function preview(CodeList $l, UploadedFile $file): array
    {
        if ($l->system || $l->source) throw new ApiException(L::t('هذه القائمة لا تُستورد من هنا.', 'This list cannot be imported here.'), 'bound_list', 409);
        $out = []; $seen = [];
        $parentList = $l->parent_key ? Codes::list($l->parent_key)->id : ($l->hierarchical ? $l->id : null);
        foreach (self::read($l, $file) as $n => $r) {
            $err = []; $attrs = [];
            foreach ($l->attrs_schema ?? [] as $f) {
                $x = $r['attr_'.$f['key']] ?? '';
                if ($x === '' || $x === null) continue;
                $attrs[$f['key']] = $f['type'] === 'bool' ? in_array(strtolower((string) $x), ['1', 'true', 'yes', 'نعم'], true) : $x;
            }
            $code = strtoupper(trim((string) ($r['code'] ?? '')));
            $parent = null;
            if (($pc = trim((string) ($r['parent_code'] ?? ''))) !== '') {
                $parent = $parentList ? CodeItem::where('code_list_id', $parentList)->where('code', strtoupper($pc))->value('id') : null;
                if (! $parent) $err['parent_code'] = L::t('رمز الأب غير موجود.', 'Unknown parent code.');
            }
            $v = ['code' => $code, 'ar' => $r['name_ar'] ?? '', 'en' => $r['name_en'] ?? '', 'parent_id' => $parent, 'valid_from' => self::date($r['valid_from'] ?? null), 'valid_to' => self::date($r['valid_to'] ?? null),
                'legacy_code' => $r['legacy_code'] ?? null, 'sort' => is_numeric($r['sort'] ?? null) ? (int) $r['sort'] : null, 'description' => $r['description'] ?? null, 'attrs' => $attrs];
            try { $clean = Codes::validate($l, $v); } catch (ApiException $e) { foreach ($e->errors as $k => $m) $err[$k === 'parent_id' ? 'parent_code' : $k] = is_array($m) ? $m[0] : $m; $clean = null; }
            if ($code !== '' && isset($seen[$code])) $err['code'] = str_replace(':n', (string) $seen[$code], L::t('الرمز مكرر في الملف (السطر :n).', 'Duplicate code in the file (row :n).'));
            $seen[$code] ??= $n;
            $out[] = ['row' => $n, 'values' => $clean ?? $v, 'errors' => $err];
        }
        if (! $out) throw ApiException::field('file', L::t('الملف فارغ.', 'The file is empty.'), 'empty');
        return $out;
    }

    public static function commit(CodeList $l, UploadedFile $file): int
    {
        $rows = self::preview($l, $file);
        if (array_filter($rows, fn ($r) => $r['errors'])) throw new ApiException(L::t('صحّح الأسطر التي فيها أخطاء ثم أعد الرفع.', 'Fix the rows with errors and upload again.'), 'import_errors', 422);
        DB::transaction(function () use ($l, $rows) { foreach ($rows as $r) CodeItem::create($r['values'] + ['code_list_id' => $l->id, 'status' => 'active']); });
        Codes::bump();
        return count($rows);
    }

    private static function read(CodeList $l, UploadedFile $file): array
    {
        $ext = strtolower($file->getClientOriginalExtension());
        if (! in_array($ext, ['xlsx', 'csv'], true)) throw ApiException::field('file', L::t('ارفع ملف Excel بصيغة xlsx أو csv.', 'Upload an Excel file (xlsx or csv).'), 'bad_file');
        $reader = $ext === 'csv' ? new CsvReader() : new XlsxReader();
        try { $reader->open($file->getRealPath()); } catch (\Throwable) { throw ApiException::field('file', L::t('تعذّرت قراءة الملف.', 'The file could not be read.'), 'bad_file'); }
        $keys = null; $rows = []; $cols = self::columns($l);
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $i => $row) {
                $cells = array_map(fn ($c) => $c instanceof \DateTimeInterface ? $c->format('Y-m-d') : (is_string($c) ? trim($c) : $c), $row->toArray());
                if ($keys === null) {
                    $keys = array_map(fn ($h) => strtolower(trim(explode(' — ', (string) $h)[0])), $cells);
                    if (! in_array('code', $keys, true) || ! in_array('name_ar', $keys, true)) { $reader->close(); throw ApiException::field('file', L::t('الملف لا يطابق القالب — نزّل القالب واملأه.', 'The file does not match the template — download it and fill it.'), 'bad_template'); }
                    continue;
                }
                if (! array_filter($cells, fn ($c) => $c !== '' && $c !== null)) continue;
                $r = []; foreach ($keys as $j => $k) if (isset($cols[$k])) $r[$k] = $cells[$j] ?? '';
                if (($r['name_ar'] ?? '') === 'مثال') continue;
                $rows[$i] = $r;
                if (count($rows) > self::MAX_ROWS) { $reader->close(); throw ApiException::field('file', L::t('الحد الأقصى '.self::MAX_ROWS.' سطر.', 'At most '.self::MAX_ROWS.' rows.'), 'too_many_rows'); }
            }
            break;
        }
        $reader->close();
        return $rows;
    }

    private static function date(mixed $x): ?string
    {
        if ($x === null || $x === '') return null;
        try { return \Carbon\Carbon::parse((string) $x)->toDateString(); } catch (\Throwable) { return null; }
    }
}
