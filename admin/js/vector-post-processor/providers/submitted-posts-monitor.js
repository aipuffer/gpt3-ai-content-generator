/** Follow persisted source jobs; closing the modal stops UI polling, not indexing. */
export function monitorSubmittedPosts({ jobs, modal, totalPosts, failedCount = 0, stopped = false, texts = {} }) {
  if (!modal?.isConnected || !jobs.length) return;
  const pending = new Map(jobs.map((job) => [String(job.job_id), job]));
  let ready = 0;
  let failed = failedCount;
  let timer;
  let disposed = false;
  let attempts = 0;
  const dispose = () => {
    disposed = true;
    window.clearTimeout(timer);
    modal.removeEventListener('aipkit:vpp-closed', dispose);
  };
  modal.addEventListener('aipkit:vpp-closed', dispose, { once: true });
  const run = async () => {
    if (disposed || !modal.isConnected) return dispose();
    if (document.visibilityState === 'hidden') {
      timer = window.setTimeout(run, 5000);
      return;
    }
    const batch = Array.from(pending.values()).slice(0, 20);
    try {
      const response = await window.aipkit_apiRequest('aipkit_get_post_indexing_status', {
        _ajax_nonce: window.aipkit_vpp_config?.nonce_index_posts,
        job_ids: batch.map((job) => job.job_id),
      });
      if (disposed || !modal.isConnected) return dispose();
      window.aipkit_vpp_updateStatus?.('', 'info');
      for (const job of response.jobs || []) {
        const id = String(job.job_id);
        if (!pending.has(id)) continue;
        const complete = job.status === 'indexed' || job.status === 'skipped_already_indexed';
        const error = job.status === 'failed';
        window.aipkit_vpp_updateProgressItem?.(job.post_id, complete ? 'success' : error ? 'error' : 'processing', error ? job.message : '');
        if (complete || error) {
          pending.delete(id);
          if (complete) ready++; else failed++;
        }
      }
      window.aipkit_vpp_updateFooterProgress?.(ready, totalPosts, texts.items_ready_progress || '%1$d of %2$d items ready');
      window.aipkit_vpp_updateProgress?.(((ready + failed) / totalPosts) * 100,
        pending.size ? texts.status_processing || 'Processing' : failed ? texts.indexing_has_errors || 'Indexing finished with errors' : stopped ? texts.submitted_items_ready || 'Submitted items are ready' : texts.indexing_complete || 'Indexing complete');
    } catch (error) {
      // Network errors are not provider failures. Keep submitted jobs processing.
      if (disposed || !modal.isConnected) return dispose();
      window.aipkit_vpp_updateStatus?.(texts.status_check_retry || 'Could not check progress. Retrying…', 'warning');
    }
    // Rotate pending jobs so a slow source cannot block updates for later items.
    for (const job of batch) {
      const id = String(job.job_id);
      if (pending.has(id)) { pending.delete(id); pending.set(id, job); }
    }
    if (!pending.size) return dispose();
    if (++attempts >= 120) {
      window.aipkit_vpp_updateStatus?.(texts.background_processing || 'Indexing continues in the background. Check Sources for progress.', 'info');
      return dispose();
    }
    timer = window.setTimeout(run, 5000);
  };
  run();
  return dispose;
}
