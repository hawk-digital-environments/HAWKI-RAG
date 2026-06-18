export type HealthCheckStatus = 'ok' | 'fail' | 'checking' | string;

export interface HealthRepairAction {
    label?: string;
    href?: string;
    kind?: string;
}

export interface HealthCheck {
    key?: string;
    title?: string;
    status?: HealthCheckStatus;
    detail?: string;
    fix?: string;
    required?: boolean;
    meta?: Record<string, unknown>;
}

export interface SystemGatePayload {
    success?: boolean;
    enforce?: boolean;
    status?: 'ready' | 'blocked' | 'disabled' | string;
    checkedAt?: string;
    required?: string[];
    checks?: HealthCheck[];
    blocking?: HealthCheck[];
    repairActions?: HealthRepairAction[];
    message?: string;
}
