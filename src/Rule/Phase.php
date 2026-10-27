<?php

namespace Valtzu\OutgoingFirewall\Rule;

enum Phase: string
{
    case Request = 'request';
    case Connect = 'connect';
}
