import * as C from "./constants.js";

window.onload = async () => {
  const request = await fetch("keys?json");
  const keys = await request.json();

  const noKeys = document.getElementById("no-keys");
  const keyTemplate = document.getElementById("key-template");
  const itemsWrapper = document.getElementById("items");

  if (!keys || keys.length < 1) {
    noKeys.classList.remove("hidden");
    return;
  };

  for (let i = 0; i < keys.length; i++) {
    let info = keys[i];

    let clone = keyTemplate.cloneNode(true);
    clone.querySelector('[data-field="filename"]').innerText = info.filename;

    let tag = "tag" in info ? info.tag.split("|")[0] : null;
    if (tag && tag in C.TAG_FRIENDLY_NAMES)
      clone.querySelector('[data-field="tag"]').innerText = C.TAG_FRIENDLY_NAMES[tag];
    else
      clone.querySelector('[data-field="tag"]').classList.add("hidden");

    clone.querySelector('[data-field="key"]').innerText = info.key;

    if ("iv" in info)
      clone.querySelector('[data-field="iv"]').innerText = info.iv;
    else
      clone.querySelector('[data-field="iv-wrapper"]').classList.add("invisible");

    clone.removeAttribute("id");
    clone.classList.remove("hidden");
    itemsWrapper.appendChild(clone);
  }
};