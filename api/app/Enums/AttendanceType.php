<?php

namespace App\Enums;

enum AttendanceType: string
{
    case CheckIn = 'check_in';
    case CheckOut = 'check_out';
}
