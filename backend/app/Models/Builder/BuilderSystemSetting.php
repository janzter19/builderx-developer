<?php
declare(strict_types=1);

namespace App\Models\Builder;

final class BuilderSystemSetting
{
    public const TABLE = 'builder_system_setting';
    public const PRIMARY_KEY = 'setting_key';

    public const STATUS_ACTIVE = 'ACTIVE';
    public const STATUS_INACTIVE = 'INACTIVE';
    public const STATUS_DELETED = 'DELETED';

    public const STATUSES = [
        self::STATUS_ACTIVE,
        self::STATUS_INACTIVE,
        self::STATUS_DELETED,
    ];

    public const GROUP_GENERAL = 'general';
    public const GROUP_LOCALIZATION = 'localization';
    public const GROUP_SECURITY = 'security';
    public const GROUP_APPLICATION = 'application';
    public const GROUP_CONTACT = 'contact';
    public const GROUP_INTERFACE = 'interface';

    public const GROUPS = [
        self::GROUP_GENERAL,
        self::GROUP_LOCALIZATION,
        self::GROUP_SECURITY,
        self::GROUP_APPLICATION,
        self::GROUP_CONTACT,
        self::GROUP_INTERFACE,
    ];

    public const FILLABLE = [
        'setting_group',
        'setting_name',
        'setting_value',
        'setting_status',
    ];
}
