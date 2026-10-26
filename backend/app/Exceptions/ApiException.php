<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * رفض قاعدة عمل برسالة ثنائية اللغة ورمز ثابت (SRS §8.1):
 * 409 حالة لا تسمح بالانتقال، 422 تحقق (مع errors لكل حقل)، 403 دور غير مسموح.
 */
class ApiException extends RuntimeException
{
    public function __construct(string $message, public readonly string $errorCode, public readonly int $status = 409, public readonly array $errors = [], public readonly array $extra = [])
    {
        parent::__construct($message);
    }

    /** خطأ تحقق على حقل واحد — يظهر تحته في الواجهة */
    public static function field(string $field, string $message, string $code): self
    {
        return new self($message, $code, 422, [$field => [$message]]);
    }

    public function render(): JsonResponse
    {
        return response()->json(array_filter(['message' => $this->getMessage(), 'code' => $this->errorCode, 'errors' => $this->errors ?: null]) + $this->extra, $this->status);
    }
}
