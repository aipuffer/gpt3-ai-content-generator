export function selectFirstAvailableOption(sel) {
    if (!sel || sel.options.length === 0) {
        return;
    }

    let firstEnabledIndex = -1;
    for (let i = 0; i < sel.options.length; i++) {
        if (!sel.options[i].disabled && sel.options[i].value) {
            firstEnabledIndex = i;
            break;
        }
    }

    sel.selectedIndex = firstEnabledIndex !== -1 ? firstEnabledIndex : 0;
}
