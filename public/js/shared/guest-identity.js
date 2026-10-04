/** Shared persistent guest identity for public plugin features. */
(function () {
  "use strict";

  let guestUuid = localStorage.getItem("aipkit_guest_uuid");
  if (!guestUuid) {
    guestUuid = ([1e7] + -1e3 + -4e3 + -8e3 + -1e11).replace(
      /[018]/g,
      (c) =>
        (
          c ^
          (crypto.getRandomValues(new Uint8Array(1))[0] & (15 >> (c / 4)))
        ).toString(16)
    );
    localStorage.setItem("aipkit_guest_uuid", guestUuid);
  }
  window.aipkit_guest_uuid = guestUuid;
})();
