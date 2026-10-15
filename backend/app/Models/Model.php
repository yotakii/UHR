<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model as Eloquent;

/**
 * أساس كل النماذج: الإسناد الجماعي مسموح عدا المعرّف (المدخلات تمر دائماً عبر التحقق)،
 * وكل كتابة تُسجَّل في سجل التدقيق — النماذج عالية التكرار أو السجلات نفسها تضع $audit = false.
 */
abstract class Model extends Eloquent
{
    use Auditable;

    protected $guarded = ['id'];
}
