import { monitorSubmittedPosts } from './submitted-posts-monitor.js';

export async function startVectorPostIndexing({
  postIds,
  postType,
  provider,
  providerLabel,
  buildRequestData,
  failureMessage,
  outerErrorLabel = "Indexing error",
  onComplete,
  actionName = "aipkit_index_posts_to_vector_store",
  nonce: nonceOverride = "",
  asynchronous = false,
}) {
  const config = window.aipkit_vpp_config || {};
  const texts = config.text || {};
  const totalPosts = postIds.length;
  let processedCount = 0;
  let successCount = 0;
  let failedCount = 0;
  const failedPosts = [];
  const submittedJobs = [];
  let stopReason = "";
  const modal = document.getElementById('aipkit_vpp_modal');
  const isCurrentModal = () => modal?.isConnected && modal.dataset.aipkitVppClosed !== 'true' && document.getElementById('aipkit_vpp_modal') === modal;

  try {
    const nonce = nonceOverride || config.nonce_index_posts;
    if (!nonce) {
      throw new Error("Nonce for indexing posts is missing in config.");
    }

    for (const postId of postIds) {
      if (!isCurrentModal()) return;
      if (window.aipkit_isIndexingStopped) {
        window.aipkit_vpp_updateStatus("Indexing stopped by user.", "warning");
        break;
      }

      const currentItemNumber = processedCount + 1;
      const postRow = document.querySelector(`#post-${postId}`);
      const postTitleEl = postRow
        ? postRow.querySelector(".title .row-title")
        : null;
      const postTitleText = postTitleEl
        ? postTitleEl.textContent.trim()
        : `Post #${postId}`;

      if (typeof window.aipkit_vpp_updateProgressItem === "function") {
        window.aipkit_vpp_updateProgressItem(
          postId,
          "processing",
          "",
          postTitleText
        );
      }

      if (typeof window.aipkit_vpp_updateProgress === "function") {
        const progressPercentage = (processedCount / totalPosts) * 100;
        const progressText =
          (texts.indexing_progress || "Indexing %1$d of %2$d...")
            .replace("%1$d", currentItemNumber)
            .replace("%2$d", totalPosts);
        window.aipkit_vpp_updateProgress(progressPercentage, progressText);
      }
      if (typeof window.aipkit_vpp_updateStatus === "function") {
        window.aipkit_vpp_updateStatus("", "info");
      }

      const requestData = {
        _ajax_nonce: nonce,
        post_ids: [postId],
        post_type: postType,
        provider,
        ...buildRequestData(),
      };

      try {
        const response = await window.aipkit_apiRequest(actionName, requestData);
        if (!isCurrentModal()) return;
        if (Array.isArray(response?.failed_posts_summary) && response.failed_posts_summary.map(String).includes(String(postId))) {
          throw new Error(response.message || failureMessage);
        }
        if (asynchronous) {
          (response?.jobs || []).forEach((job) => submittedJobs.push({ ...job, post_id: postId }));
        }
        successCount++;
        if (typeof window.aipkit_vpp_updateProgressItem === "function") {
          window.aipkit_vpp_updateProgressItem(
            postId,
            asynchronous ? 'submitted' : 'success',
            '',
            postTitleText
          );
        }
      } catch (individualError) {
        if (!isCurrentModal()) return;
        failedCount++;
        failedPosts.push({
          id: postId,
          title: postTitleText,
          error: individualError.message,
        });
        const lostCloudResponse = requestData.embedding_provider?.toLowerCase() === "aipuffercloud"
          && (!individualError.code || ["timeout", "aborted", "gateway_timeout", "invalid_html_response", "invalid_json_response"].includes(individualError.code))
          && !individualError.data?.code;
        if (individualError.data?.stop_batch || lostCloudResponse) {
          stopReason = individualError.message || texts.status_stopped || "Indexing stopped";
          window.aipkit_isIndexingStopped = true;
        }
        console.error(
          `VPP ${providerLabel} Provider: Failed to index Post ID ${postId}:`,
          individualError
        );
        if (typeof window.aipkit_vpp_updateProgressItem === "function") {
          const errorMessage =
            individualError?.message ||
            texts.status_error ||
            "An error occurred.";
          window.aipkit_vpp_updateProgressItem(
            postId,
            "error",
            errorMessage,
            postTitleText
          );
        }
      }
      processedCount++;
      if (typeof window.aipkit_vpp_updateFooterProgress === "function") {
        window.aipkit_vpp_updateFooterProgress(
          successCount,
          totalPosts,
          asynchronous ? texts.items_submitted_progress : undefined
        );
      }
      if (stopReason) break;
    }

    let finalMessage = "";
    if (stopReason) {
      finalMessage = stopReason;
    } else if (window.aipkit_isIndexingStopped && processedCount < totalPosts) {
      finalMessage = `Indexing stopped. ${successCount} successful, ${failedCount} failed.`;
    } else if (failedCount > 0) {
      console.error("Failed Posts:", failedPosts);
      finalMessage = `Indexing finished with ${failedCount} failed item${
        failedCount === 1 ? "" : "s"
      }.`;
    }
    if (typeof window.aipkit_vpp_updateStatus === "function") {
      window.aipkit_vpp_updateStatus(
        finalMessage,
        failedCount > 0 || window.aipkit_isIndexingStopped
          ? "warning"
          : "success"
      );
    }
    if (typeof window.aipkit_vpp_updateProgress === "function") {
      const progressLabel =
        stopReason || (window.aipkit_isIndexingStopped && processedCount < totalPosts)
          ? texts.status_stopped || "Indexing stopped"
          : asynchronous ? texts.submission_complete || 'Submitted — processing in background' : texts.indexing_complete || "Indexing complete";
      window.aipkit_vpp_updateProgress(
        (processedCount / totalPosts) * 100,
        progressLabel
      );
    }

    if (typeof onComplete === "function") {
      await onComplete({ successCount, failedCount, totalPosts, unprocessedCount: totalPosts - processedCount });
    }
    if (!isCurrentModal()) return;
    if (asynchronous) {
      monitorSubmittedPosts({ jobs: submittedJobs, modal, totalPosts, failedCount, stopped: !!stopReason || window.aipkit_isIndexingStopped, texts });
    }
  } catch (error) {
    if (!isCurrentModal()) return;
    console.error(`VPP ${providerLabel} Provider: ${outerErrorLabel}:`, error);
    if (typeof window.aipkit_vpp_updateProgress === "function") {
      window.aipkit_vpp_updateProgress(
        100,
        texts.status_error || "An error occurred."
      );
    }
    if (typeof window.aipkit_vpp_updateStatus === "function") {
      window.aipkit_vpp_updateStatus(
        error.message || failureMessage,
        "error"
      );
    }
  } finally {
    if (isCurrentModal() && typeof window.aipkit_vpp_resetModalAfterProcessing === "function") {
      window.aipkit_vpp_resetModalAfterProcessing();
    }
  }
}
