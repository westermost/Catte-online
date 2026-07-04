<?php

namespace App\Enums;

enum PlayerStatus: string
{
    case CONNECTED = 'connected';
    case DISCONNECTED = 'disconnected';
    case ELIMINATED = 'eliminated';
    case KICKED = 'kicked';
    case LEFT = 'left';
}
