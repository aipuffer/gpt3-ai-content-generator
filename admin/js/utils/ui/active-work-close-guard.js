/** Confirm intentional dismissal without stopping work when the user keeps it open. */
export function createActiveWorkCloseGuard({ modal, isBusy, getMessage, title, confirmText, cancelText, onClose }) {
  let confirming = false;
  let closed = false;
  const close = () => {
    confirming = false;
    if (closed || !modal.isConnected) return;
    closed = true;
    onClose();
  };

  return () => {
    if (closed || confirming || !modal.isConnected) return;
    if (!isBusy()) {
      close();
      return;
    }
    // Keep active work open if the shared confirmation UI is unavailable.
    if (typeof window.aipkit_showConfirmModal !== "function") return;
    confirming = true;
    const trigger = document.activeElement;
    window.aipkit_showConfirmModal(getMessage(), {
      title,
      confirmText,
      cancelText,
      variant: "warning",
      preferCancel: true,
      onConfirm: close,
      onCancel: () => {
        confirming = false;
        if (modal.isConnected && trigger?.isConnected) trigger.focus();
      },
    });
  };
}
