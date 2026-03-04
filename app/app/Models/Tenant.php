<?php

declare(strict_types=1);

namespace App\Models;

use Stancl\Tenancy\Contracts\TenantWithDatabase;
use Stancl\Tenancy\Database\Concerns\HasDatabase;
use Stancl\Tenancy\Database\Concerns\HasDomains;
use Stancl\Tenancy\Database\Models\Tenant as BaseTenant;

/**
 * Tenant = Région (IDF, PACA, etc.)
 *
 * @property string $id
 * @property string $name
 * @property string $code  Code région (idf, paca, lyon...)
 * @property array $data   Extra config (adresse, contact, etc.)
 */
class Tenant extends BaseTenant implements TenantWithDatabase
{
    use HasDatabase, HasDomains;

    public static function getCustomColumns(): array
    {
        return [
            'id',
            'name',
            'code',
        ];
    }
}
