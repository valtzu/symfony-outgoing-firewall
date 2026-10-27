<?php

namespace Valtzu\OutgoingFirewall\Rule;

enum Action: string
{
    case Allow = 'allow';
    case Block = 'block';
}
