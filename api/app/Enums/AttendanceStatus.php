<?php

namespace App\Enums;

enum AttendanceStatus: string
{
    case Success = 'success';
    case UnknownCard = 'unknown_card';
    case Inactive = 'inactive';
    case Duplicate = 'duplicate';
}
