<?php

return [
    'read_only' => filter_var(env('demo.read_only', false), FILTER_VALIDATE_BOOLEAN),
    'group_id' => filter_var(env('demo.group_id', 0), FILTER_VALIDATE_INT),
];
