/** One report after an explicit Connect response. Never waits, retries, sends credentials or runs on page load. */
export function reportConnectionDiagnostic(data) {
    const report = data?.connectionDiagnostic;
    if (!report || report.consentVersion !== 'cloud-connect-2026-10-04'
        || typeof report.reference !== 'string' || !/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/.test(report.reference)) return;
    let timer;
    try {
        // No WordPress cookies are sent to Cloud. text/plain avoids a cross-origin preflight.
        const controller = new AbortController();
        timer = setTimeout(() => controller.abort(), 2000);
        void fetch('https://puffercloud.dev/api/connection-diagnostics', {
            method: 'POST', credentials: 'omit', headers: { 'Content-Type': 'text/plain' },
            body: JSON.stringify(report), keepalive: true, signal: controller.signal,
        }).catch(() => {}).finally(() => clearTimeout(timer));
    } catch (_) { clearTimeout(timer); /* Setup must work even when diagnostic reporting is unavailable. */ }
}
