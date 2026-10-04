/**
 * Creates a scoped Google File Search ingestion-job poller.
 *
 * Consumers provide their own request and refresh callbacks so the polling
 * contract stays shared without coupling Sources and Chatbot UI state.
 */
export function createGoogleFileSearchJobPoller({
  request,
  getNonce,
  onSettled = () => {},
  maxAttempts = 40,
  pollDelay = 3000,
  retryDelay = 3500,
} = {}) {
  const activeJobs = new Set();

  const invokeCallback = (callback, ...args) => {
    if (typeof callback !== "function") {
      return;
    }
    try {
      callback(...args);
    } catch (error) {
      window.setTimeout(() => {
        throw error;
      }, 0);
    }
  };

  const poll = (jobId, options = {}) => {
    const normalizedJobId = String(jobId || "").trim();
    if (
      !normalizedJobId ||
      activeJobs.has(normalizedJobId) ||
      typeof request !== "function" ||
      typeof getNonce !== "function"
    ) {
      return false;
    }

    const nonce = getNonce();
    if (!nonce) {
      return false;
    }

    activeJobs.add(normalizedJobId);
    let attempt = 0;
    let timer = null;
    let finished = false;
    const finish = (details) => {
      if (finished) return;
      finished = true;
      window.clearTimeout(timer);
      options.signal?.removeEventListener("abort", abort);
      activeJobs.delete(normalizedJobId);
      invokeCallback(options.onSettled || onSettled, details);
    };
    const abort = () => finish({
      jobId: normalizedJobId, reason: "aborted", status: "processing",
    });
    options.signal?.addEventListener("abort", abort, { once: true });

    const run = () => {
      if (finished) return;
      if (options.signal?.aborted) {
        abort();
        return;
      }

      Promise.resolve().then(() => {
        if (finished) return;
        return request("aipkit_get_google_file_search_job_status", {
          _ajax_nonce: nonce,
          job_id: normalizedJobId,
        }, { signal: options.signal });
      })
        .then((response) => {
          if (finished) return;
          const status = String(response?.status || "processing").toLowerCase();
          invokeCallback(options.onStatus, status, response || {});
          if (finished) return;

          if (response?.done || status === "indexed" || status === "failed") {
            finish({
              jobId: normalizedJobId,
              reason: "complete",
              response: response || {},
              status,
            });
            return;
          }

          if (attempt >= maxAttempts - 1) {
            finish({
              jobId: normalizedJobId,
              reason: "timeout",
              response: response || {},
              status,
            });
            return;
          }

          attempt += 1;
          if (!finished) timer = window.setTimeout(run, pollDelay);
        })
        .catch((error) => {
          if (finished) return;
          invokeCallback(options.onError, error, attempt + 1);
          if (finished) return;

          if (attempt >= maxAttempts - 1) {
            finish({
              error,
              jobId: normalizedJobId,
              reason: "error",
              status: "processing",
            });
            return;
          }

          attempt += 1;
          if (!finished) timer = window.setTimeout(run, retryDelay);
        });
    };

    run();
    return true;
  };

  return {
    isPolling: (jobId) => activeJobs.has(String(jobId || "").trim()),
    poll,
  };
}
