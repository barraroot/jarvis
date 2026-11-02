<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Table;

#[Table('result_types')]
class ResultType extends LookupModel
{
    public const string TEXT = 'text';

    public const string LIST = 'list';

    public const string TABLE = 'table';

    public const string REPORT = 'report';

    public const string SEARCH_RESULTS = 'search_results';

    public const string TASK_CARD = 'task_card';

    public const string DATA = 'data';

    public const string EXECUTION_STATUS = 'execution_status';

    public const string DOCUMENT = 'document';
}
