<?php

declare(strict_types=1);

namespace Botect;

enum Operation: string
{
    case RefreshChallengeList = 'refresh_challenge_list';
    case RefreshVerdict = 'refresh_verdict';
    case AssertLoggedIn = 'assert_logged_in';
    case RecordPage = 'record_page';
    case ForwardEvents = 'forward_events';
}
