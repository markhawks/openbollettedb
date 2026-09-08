(() => {
  const enhancedInputs = new WeakSet();

  function italianToIso(value) {
    const match = /^(\d{2})\/(\d{2})\/(\d{4})$/.exec(value.trim());
    if (!match) return '';
    const day = Number(match[1]);
    const month = Number(match[2]);
    const year = Number(match[3]);
    const date = new Date(year, month - 1, day);
    if (date.getFullYear() !== year || date.getMonth() !== month - 1 || date.getDate() !== day) return '';
    return `${match[3]}-${match[2]}-${match[1]}`;
  }

  function isoToItalian(value) {
    const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value);
    return match ? `${match[3]}/${match[2]}/${match[1]}` : '';
  }

  function enhanceDateInput(input) {
    if (enhancedInputs.has(input) || input.readOnly || input.disabled) return;
    enhancedInputs.add(input);

    const wrapper = document.createElement('span');
    wrapper.className = 'italian-date-control';
    input.parentNode.insertBefore(wrapper, input);
    wrapper.appendChild(input);

    const nativePicker = document.createElement('input');
    nativePicker.type = 'date';
    nativePicker.className = 'italian-date-native-picker';
    nativePicker.tabIndex = -1;
    nativePicker.setAttribute('aria-hidden', 'true');
    nativePicker.value = italianToIso(input.value);

    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'italian-date-calendar-button';
    button.textContent = '📅';
    button.title = 'Scegli la data dal calendario';
    button.setAttribute('aria-label', 'Scegli la data dal calendario');

    input.addEventListener('input', () => {
      nativePicker.value = italianToIso(input.value);
    });
    nativePicker.addEventListener('change', () => {
      if (!nativePicker.value) return;
      input.value = isoToItalian(nativePicker.value);
      input.dispatchEvent(new Event('change', { bubbles: true }));
      input.focus();
    });
    button.addEventListener('click', () => {
      nativePicker.value = italianToIso(input.value);
      if (typeof nativePicker.showPicker === 'function') nativePicker.showPicker();
      else nativePicker.click();
    });

    wrapper.append(nativePicker, button);
  }

  function enhanceAll(root = document) {
    if (root.matches?.('input[placeholder="gg/mm/aaaa"]')) enhanceDateInput(root);
    root.querySelectorAll?.('input[placeholder="gg/mm/aaaa"]').forEach(enhanceDateInput);
  }

  enhanceAll();
  new MutationObserver(mutations => {
    mutations.forEach(mutation => mutation.addedNodes.forEach(node => {
      if (node.nodeType === Node.ELEMENT_NODE) enhanceAll(node);
    }));
  }).observe(document.body, { childList: true, subtree: true });
})();
