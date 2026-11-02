/**
 * Typed result payloads (US-11.1) — mirror of `resources/schemas/results/*.json`.
 * The backend validates before persisting; these shapes drive the dedicated
 * Vue renderers under `components/results/`.
 */

export interface SuggestedAction {
    label: string;
    request: string;
}

export interface TextResultPayload {
    type: 'text';
    text: string;
    speech?: string;
    suggested_actions?: SuggestedAction[];
    meta?: { result_invalid?: boolean };
}

export interface ListItem {
    label: string;
    detail?: string;
}

export interface ListResultPayload {
    type: 'list';
    title?: string;
    items: ListItem[];
    speech?: string;
    suggested_actions?: SuggestedAction[];
}

export type TableCell = string | number | boolean | null;

export interface TableResultPayload {
    type: 'table';
    columns: string[];
    rows: TableCell[][];
    caption?: string;
    speech?: string;
    suggested_actions?: SuggestedAction[];
}

export interface ReportSection {
    heading: string;
    body: string;
}

export interface ReportResultPayload {
    type: 'report';
    title: string;
    sections: ReportSection[];
    speech?: string;
    suggested_actions?: SuggestedAction[];
}

export interface SearchResultHit {
    title: string;
    url?: string;
    snippet: string;
    source: string;
}

export interface SearchResultsResultPayload {
    type: 'search_results';
    query: string;
    results: SearchResultHit[];
    speech?: string;
    suggested_actions?: SuggestedAction[];
}

export interface TaskCardResultPayload {
    type: 'task_card';
    task_id: number;
    title: string;
    status: string;
    progress: number;
    speech?: string;
    suggested_actions?: SuggestedAction[];
}

export interface DataSeries {
    label?: string;
    values: number[];
}

export interface DataResultPayload {
    type: 'data';
    label: string;
    value: string | number;
    unit?: string;
    series?: DataSeries;
    speech?: string;
    suggested_actions?: SuggestedAction[];
}

export interface ExecutionStatusResultPayload {
    type: 'execution_status';
    entity: 'tool' | 'agent';
    name?: string;
    status: string;
    message: string;
    speech?: string;
    suggested_actions?: SuggestedAction[];
}

export interface DocumentResultPayload {
    type: 'document';
    title: string;
    format: 'markdown' | 'plain';
    body: string;
    speech?: string;
    suggested_actions?: SuggestedAction[];
}

export type ResultPayload =
    | TextResultPayload
    | ListResultPayload
    | TableResultPayload
    | ReportResultPayload
    | SearchResultsResultPayload
    | TaskCardResultPayload
    | DataResultPayload
    | ExecutionStatusResultPayload
    | DocumentResultPayload;
