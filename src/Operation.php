<?php

declare(strict_types=1);

namespace Botect;

enum Operation: string
{
    case RefreshVerdict = 'refresh_verdict';
    case AssertLoggedIn = 'assert_logged_in';
    case RecordPage = 'record_page';
    case ForwardEvents = 'forward_events';
}
