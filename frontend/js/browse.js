let heartbeat;
let initializing = true;
let selectedFile;
let selectedFileParentPath;
let extractingDmg = false;
let decryptingDmg = false;
let disconnected = false;
let extractedDmgs = [];
let dmgInfo = [];
let listingElements = [];

function bytesToUnitsString(bytes) {
  if (!bytes && bytes !== 0) return "Unknown";

  let units = ["Bytes", "KiB", "MiB", "GiB"];
  let unit = 0;
  let amount = bytes;

  while (true) {
    if (amount < 1024) {
      break;
    }
    unit += 1;
    amount /= 1024;
  }

  return `${Math.round(amount * 10) / 10} ${units[unit]}`;
}

function removeTrailingSlash(path) {
  while (path.at(-1) == "/") {
    path = path.slice(0, -1);
  }
  return path;
}

function pathNeedsDmgExtraction(path) {
  path = removeTrailingSlash(path);
  if (!path.includes(".dmg")) return false;

  let pathParts = path.split("/");
  for (let i = 0; i < pathParts.length; i++) {
    let part = pathParts[i];
    if (part.includes(".dmg")) {
      if (extractedDmgs.includes(part)) return false;
      return part;
    }
  }

  return false;
}

function getIconNameForFile(filename, tag = null) {
  let extension = filename.split(".").pop();

  if (tag in TAG_ICONS) {
    return TAG_ICONS[tag];
  } else if (extension in EXTENSION_ICONS) {
    return EXTENSION_ICONS[extension];
  }

  return "file";
}

function getElem(id) {
  return document.getElementById(id);
}

window.onload = () => {
  let browsePath = removeTrailingSlash("/" + window.location.pathname.split("/").slice(3).join("/"));

  const initPage = getElem("init-page");
  const initBar = getElem("init-progress-bar");
  const initBarFill = getElem("init-progress-bar-fill");
  const initStatus = getElem("init-status-text");

  const listingPage = getElem("listing-page");
  const listingPathText = getElem("listing-path");
  const listingSearchBox = getElem("listing-search-box");
  const listingSearchStatus = getElem("listing-search-status");
  const listingSearchClearButton = getElem("search-clear-button");
  const listingFilesView = getElem("listing-files-view");
  const listingLeftSidebar = getElem("listing-left-sidebar");
  const listingFileTemplate = getElem("listing-file-template");
  const searchResultsPathTemplate = getElem("search-results-path-template");
  listingFileTemplate.remove();
  searchResultsPathTemplate.remove();

  const infoView = getElem("listing-info-view");
  const infoViewFileStats = getElem("info-view-file-stats");
  const infoViewIcon = getElem("info-view-stats-icon");
  const infoViewFilename = getElem("info-view-stats-filename");
  const infoViewTag = getElem("info-view-stats-tag");
  const infoViewSize = getElem("info-view-stats-size");
  const infoViewDownloadLink = getElem("info-view-stats-download");
  const infoViewDownloadOptions = getElem("info-view-stats-download-options");
  const infoViewStart = getElem("info-start");
  const infoViewExtracting = getElem("info-extracting");
  const extractingBar = getElem("extracting-progress-bar");
  const extractingBarFill = getElem("extracting-progress-bar-fill");
  const extractingStatus = getElem("extracting-status-text");

  const contextMenu = getElem("context-menu");
  const contextMenuFilename = getElem("context-menu-filename");
  const contextMenuInfo = getElem("context-menu-info");
  const contextMenuDownload = getElem("context-menu-download");
  const contextMenuDownloadRaw = getElem("context-menu-download-raw");
  const contextMenuDownloadXml = getElem("context-menu-download-xml");
  const contextMenuDownloadJson = getElem("context-menu-download-json");
  const contextMenuDownloadDecrypted = getElem("context-menu-download-decrypted");
  const contextMenuDownloadPng = getElem("context-menu-download-png");

  const tooltip = getElem("tooltip");

  const imageViewer = getElem("info-image-viewer");
  const imageViewerPreview = getElem("image-viewer-preview");
  const imageViewerDimensions = getElem("image-viewer-dimensions");

  const isMouse = window.matchMedia("(pointer: fine)").matches;
  const ipswId = window.location.pathname.split("/")[1];
  const wsProtocol = window.location.protocol == "https:" ? "wss" : "ws";
  const ws = new WebSocket(`${wsProtocol}://${window.location.host}/${ipswId}/ws`);

  function getRawUrl(filename, path = browsePath) {
    if (path == "/") path = "";
    return `/${ipswId}/raw${path}/${filename}`;
  }

  function hideInfoViews() {
    for (let i = 0; i < infoView.children.length; i++) {
      let child = infoView.children[i];
      if (child.id != infoViewFileStats.id) child.classList.add("hidden");
    }
  }

  function changeInfoView(view = null) {
    hideInfoViews();
    if (view) view.classList.remove("hidden");
  }

  function getListingElementFromFilename(filename) {
    return listingFilesView.querySelector(`[data-filename="${filename}"]`);
  }

  function setSelectedFile(listingElement) {
    if (selectedFile) selectedFile.classList.remove("bg-slate-200", "dark:bg-zinc-700");
    selectedFile = listingElement;
    selectedFileParentPath = browsePath;
    listingElement.classList.add("bg-slate-200", "dark:bg-zinc-700");
  }

  function sendCommand(command, args) {
    ws.send(JSON.stringify({"command": command, ...args}));
  }

  function navigateTo(path, pushState = true) {
    if (path.at(0) != "/")
      path = "/" + path;

    let dmgToExtract = pathNeedsDmgExtraction(path);
    if (dmgToExtract && !extractingDmg) {
      extractingDmg = dmgToExtract;
      showFilePreview({...dmgInfo[dmgToExtract], "name": dmgToExtract});
      changeInfoView(infoViewExtracting);
      extractingStatus.innerText = "Waiting...";
      extractingBarFill.style.width = "0%";
      listingPathText.innerHTML += LISTING_PATH_EXTRACTING_TEXT;
      toggleLeftSidebar(false);
    }

    browsePath = removeTrailingSlash(path);
    listingElements.length = 0;
    listingFilesView.replaceChildren();
    listingPathText.classList.remove("invisible");
    listingPathText.innerHTML = browsePath == "" ? "/" : browsePath.replaceAll("/", "<wbr>/");
    listingSearchBox.value = "";
    if (pushState) history.pushState({"path": path}, "", removeTrailingSlash(`${window.location.origin}/${ipswId}/browse${path}`));

    sendCommand("listing", {"location": path});
  }

  function dismissContextMenu() {
    contextMenu.classList.remove("opacity-100", "visible");
    contextMenu.classList.add("opacity-0");
    contextMenu.style.left = "0";
    contextMenu.style.top = "0";
  }

  function toggleLeftSidebar(enable = null) {
    if (enable === null) enable = listingLeftSidebar.classList.contains("pointer-events-none");

    if (enable) {
      listingLeftSidebar.classList.remove("pointer-events-none", "opacity-50");
    } else {
      listingLeftSidebar.classList.add("pointer-events-none", "opacity-50");
    }
  }

  function showFilePreview(info, path = browsePath) {
    const filename = info.name;
    let tag;
    if (info.tag) tag = info.tag.split("|")[0];
    else tag = null;

    const type = getIconNameForFile(filename, tag);
    const rawUrl = getRawUrl(filename, path);
    changeInfoView();

    // File stats (top)
    infoViewFilename.innerText = filename;
    
    if (tag && TAG_FRIENDLY_NAMES[tag]) {
      infoViewTag.innerText = `(${TAG_FRIENDLY_NAMES[tag]})`;
      infoViewTag.classList.remove("hidden");
    } else {
      infoViewTag.classList.add("hidden");
    }

    infoViewSize.innerText = bytesToUnitsString(info.size);

    infoViewIcon.src = `${ASSETS_DIR}/${type}.svg`;
    infoViewIcon.alt = type;

    infoViewDownloadLink.onclick = () => {
      window.location.href = getRawUrl(filename) + (info.cgbi ? "?defry" : "");
    };
    infoViewDownloadOptions.onclick = () => {
      const rect = infoViewDownloadOptions.getBoundingClientRect();
      openContextMenu(info, rect.left - contextMenu.clientWidth + infoViewDownloadOptions.clientWidth, rect.bottom + 4, path);
    };

    infoViewFileStats.classList.remove("hidden");



    if (type == "image" || type == "applelogo") {
      let url = rawUrl;
      if (info.cgbi)
        url += "?defry";
      else if (tag == "ibootim" || tag == "applelogo")
        url += "?png";

      imageViewerPreview.src = url;
      imageViewerPreview.alt = filename;

      imageViewerPreview.onload = () => {
        changeInfoView(imageViewer);
        imageViewerDimensions.innerText = `(${imageViewerPreview.naturalWidth} x ${imageViewerPreview.naturalHeight} px)`;
      };
    }
  }

  function openContextMenu(info, x, y, path = browsePath, listingElement = null) {
    const filename = info.name;
    const extension = filename.split(".").pop();
    let tag;
    if (info.tag) tag = info.tag.split("|")[0];
    else tag = null;

    if (listingElement) {
      contextMenuFilename.classList.remove("hidden");
      contextMenuFilename.innerText = filename;
    } else {
      contextMenuFilename.classList.add("hidden");
    }

    if (info.is_dir || !listingElement) {
      contextMenuInfo.classList.add("hidden");
    } else {
      contextMenuInfo.classList.remove("hidden");
      contextMenuInfo.onclick = () => {
        dismissContextMenu();
        setSelectedFile(listingElement);
        showFilePreview(info, path);
      };
    }

    const download = (event) => {
      window.location.href = event.target.closest("button").getAttribute("data-url");
      dismissContextMenu();
    };

    const rawUrl = getRawUrl(filename, path);
    contextMenuDownload.setAttribute("data-url", rawUrl);
    contextMenuDownload.onclick = download;
    contextMenuDownloadRaw.onclick = download;
    contextMenuDownloadXml.onclick = download;
    contextMenuDownloadJson.onclick = download;
    contextMenuDownloadPng.onclick = download;

    contextMenuDownloadRaw.classList.add("hidden");
    contextMenuDownloadXml.classList.add("hidden");
    contextMenuDownloadJson.classList.add("hidden");

    if (extension == "png" && info.cgbi) {
      contextMenuDownload.setAttribute("data-url", rawUrl + "?defry");
      contextMenuDownloadRaw.classList.remove("hidden");
      contextMenuDownloadRaw.setAttribute("data-url", rawUrl);
    } else if (info.plist_type) {
      if (info.plist_type != "xml") {
        contextMenuDownloadXml.setAttribute("data-url", rawUrl + "?xml");
        contextMenuDownloadXml.classList.remove("hidden");
      }
      contextMenuDownloadJson.setAttribute("data-url", rawUrl + "?json");
      contextMenuDownloadJson.classList.remove("hidden");
    }

    if (info.is_dir) {
      contextMenuDownload.onclick = () => {
        extractingDmg = filename;
        decryptingDmg = rawUrl;
        infoViewFileStats.classList.add("hidden");
        changeInfoView(infoViewExtracting);
        extractingStatus.innerText = "Waiting...";
        extractingBarFill.style.width = "0%";
        toggleLeftSidebar(false);

        sendCommand("storefolder", {"path": `${path}/${filename}`});
        dismissContextMenu();
      };
    }

    if (info.has_key === true) {
      contextMenuDownloadDecrypted.onclick = () => {
        if (DIR_LIKE_FILES.includes(extension) && !extractedDmgs.includes(filename)) {
          extractingDmg = filename;
          decryptingDmg = rawUrl + "?decrypt";
          showFilePreview(info);
          changeInfoView(infoViewExtracting);
          extractingStatus.innerText = "Waiting...";
          extractingBarFill.style.width = "0%";
          toggleLeftSidebar(false);
          
          sendCommand("decryptdmg", {"path": `${path}/${filename}`});
        } else {
          window.location.href = rawUrl + "?decrypt";
        }

        dismissContextMenu();
      };
      contextMenuDownloadDecrypted.classList.remove("hidden");

      if (tag == "ibootim" || tag == "applelogo") {
        contextMenuDownloadPng.setAttribute("data-url", rawUrl + "?png");
        contextMenuDownloadPng.classList.remove("hidden");
      } else {
        contextMenuDownloadPng.classList.add("hidden");
      }
    } else {
      contextMenuDownloadDecrypted.classList.add("hidden");
    }

    if (y + contextMenu.offsetHeight > window.innerHeight) y = window.innerHeight - contextMenu.offsetHeight;
    if (x + contextMenu.offsetWidth > window.innerWidth) x = window.innerWidth - contextMenu.offsetWidth;

    contextMenu.classList.remove("opacity-0");
    contextMenu.classList.add("opacity-100", "visible");
    contextMenu.style.left = `${x}px`;
    contextMenu.style.top = `${y}px`;
  }

  ws.onmessage = (event) => {
    let data = JSON.parse(event.data);

    if (initializing) {
      function initInitPage() {
        if (!initPage.classList.contains("invisible")) return;
        initPage.classList.remove("invisible");
        initBarFill.style.width = "0%";
      }

      if (data.status == "done") {
        initializing = false;
        initBarFill.style.width = "100%";
        initStatus.innerText = "Ready";
        setTimeout(() => {
          initPage.classList.add("invisible");
          listingPage.classList.remove("invisible");
          if (pathNeedsDmgExtraction(browsePath))
            sendCommand("dmginfo", {"path": browsePath});
          else
            navigateTo(browsePath, false);
        }, initPage.classList.contains("invisible") ? 0 : 1000);
      } else if (data.status == "error") {
        initInitPage();
        initStatus.innerText = `Error: ${data.message}`;
        initBar.classList.add("hidden");
        disconnected = true;
        ws.close();
      } else if (data.status == "downloading" || data.status == "extracting") {
        initInitPage();
        let percent = 0;

        let bytesDone;
        let bytesTotal;
        let statusText;
        if (data.status == "downloading") {
          bytesDone = data.bytes_downloaded;
          bytesTotal = data.bytes_total;
          percent = data.bytes_downloaded / data.bytes_total * 100;
          statusText = "Downloading IPSW...";
        } else {
          bytesDone = data.bytes_extracted;
          bytesTotal = data.bytes_total;
          percent = data.bytes_extracted / data.bytes_total * 100;
          statusText = "Extracting IPSW...";
        }
        bytesDone = bytesToUnitsString(bytesDone);
        bytesTotal = bytesToUnitsString(bytesTotal);
        if (bytesDone.split(" ")[1] == bytesTotal.split(" ")[1]) bytesDone = bytesDone.split(" ")[0];
        initStatus.innerText = `${statusText} (${bytesDone}/${bytesTotal})`;

        if ("steps_done" in data && "steps_total" in data) {
          percent = percent / data.steps_total;
          percent += 100 / data.steps_total * data.steps_done;
        }

        percent = Math.round(percent);
        initBarFill.style.width = `${percent}%`;
      }
    }

    if (extractingDmg) {
      if (data.status == "done") {
        extractingStatus.innerText = "Done";
        extractingBarFill.style.width = "100%";
        toggleLeftSidebar(true);

        if (decryptingDmg) {
          window.location.href = decryptingDmg;
          decryptingDmg = false;
        } else {
          extractedDmgs.push(extractingDmg);
        }
        
        extractingDmg = false;
      } else if (data.status == "error") {
        extractingStatus.innerText = `Error: ${data.message}`;
        extractingBar.classList.add("hidden");
        extractingDmg = false;
        decryptingDmg = false;
      } else if (["decrypting", "extracting", "storing"].includes(data.status)) {
        let percent = 0;

        let bytesDone;
        let bytesTotal;
        if (data.status == "decrypting") {
          bytesDone = data.bytes_decrypted;
          bytesTotal = data.bytes_total;
          percent = data.bytes_decrypted / data.bytes_total * 100;

          bytesDone = bytesToUnitsString(bytesDone);
          bytesTotal = bytesToUnitsString(bytesTotal);
          if (bytesDone.split(" ")[1] == bytesTotal.split(" ")[1]) bytesDone = bytesDone.split(" ")[0];
          extractingStatus.innerText = `Decrypting ${extractingDmg}... (${bytesDone}/${bytesTotal})`;
        } else if (data.status == "extracting") {
          percent = data.percent_completed;
          extractingStatus.innerText = `Extracting ${extractingDmg}... (${percent}%)`;
        } else if (data.status == "storing") {
          percent = data.percent_completed;
          extractingStatus.innerText = `Zipping ${extractingDmg}... (${percent}%)`;
        }

        if ("steps_done" in data && "steps_total" in data) {
          percent = percent / data.steps_total;
          percent += 100 / data.steps_total * data.steps_done;
        }

        percent = Math.round(percent);
        extractingBarFill.style.width = `${percent}%`;
      } else if (data.status == "finalizing") {
        extractingStatus.innerText = "Finalizing...";
        extractingBarFill.style.width = "99%";
      }
    }

    if (["listing", "results"].includes(data.status)) {
      const isSearchResults = data.status == "results";

      listingPathText.innerHTML = listingPathText.innerHTML.replaceAll(LISTING_PATH_EXTRACTING_TEXT, "");

      let listing = data[data.status];
      if (isSearchResults) {
        listing = listing.sort((a, b) => a.name.length - b.name.length);
        listing = listing.sort((a, b) => a.path.localeCompare(b.path));
      }
      let lastDirPos = -1;
      listing.forEach((file, index) => {
        if (file.is_dir || (isSearchResults && file.path == browsePath)) {
          lastDirPos++;
          listing.splice(index, 1);
          listing.splice(lastDirPos, 0, file);
        }
      });

      if (browsePath != "" && browsePath != "/" && !isSearchResults) {
        let parentEntry = listingFileTemplate.cloneNode(true);

        parentEntry.querySelector('[data-field="filename"]').innerText = "Parent directory";
        parentEntry.querySelector('[data-field="tag"]').classList.add("hidden");
        parentEntry.querySelector('[data-field="dir-arrow"]').classList.remove("hidden");
        parentEntry.querySelector('[data-field="icon"]').src = `${ASSETS_DIR}/parent-dir.svg`;
        parentEntry.querySelector('[data-field="icon"]').alt = "Up";

        parentEntry.onclick = () => {
          navigateTo(browsePath.split("/").slice(0, -1).join("/"));
        };

        parentEntry.classList.remove("hidden");
        listingFilesView.appendChild(parentEntry);
        listingElements.push(parentEntry);
      }

      let lastPath = "";
      let container = listingFilesView;
      for (let i = 0; i < listing.length; i++) {
        const info = listing[i];
        const filename = info.name;
        let path = "path" in info ? info.path : browsePath;
        let clone = listingFileTemplate.cloneNode(true);
        clone.querySelector('[data-field="filename"]').innerText = filename;
        
        if (isSearchResults && info.path != lastPath && info.path != browsePath) {
          lastPath = info.path;
          container = document.createElement("div");
          listingFilesView.appendChild(container);

          let pathClone = searchResultsPathTemplate.cloneNode(true);
          pathClone.removeAttribute("id");
          pathClone.innerHTML = info.path == "" ? "/" : info.path.replaceAll("/", "<wbr>/");
          pathClone.classList.remove("hidden");
          container.appendChild(pathClone);
        }

        let tag = null;
        if ("tag" in info) {
          tag = info.tag.split("|")[0];
          if (tag in TAG_FRIENDLY_NAMES) {
            clone.querySelector('[data-field="tag"]').innerText = `(${TAG_FRIENDLY_NAMES[tag]})`;
            clone.querySelector('[data-field="tag"]').classList.remove("hidden");
          }
        }

        let extension = filename.split(".").pop();
        if (info.is_dir || DIR_LIKE_FILES.includes(extension))
          clone.querySelector('[data-field="dir-arrow"]').classList.remove("hidden");

        if (extractingDmg == filename) {
          clone.querySelector('[data-field="dir-arrow"]').src = `${ASSETS_DIR}/loader.svg`;
          clone.querySelector('[data-field="dir-arrow"]').classList.add("animate-spin");
        }

        if (info.is_dir) {
          clone.querySelector('[data-field="icon"]').src = `${ASSETS_DIR}/dir.svg`;
          clone.querySelector('[data-field="icon"]').alt = "Directory";
        } else {
          let icon = getIconNameForFile(filename, tag);
          clone.querySelector('[data-field="icon"]').src = `${ASSETS_DIR}/${icon}.svg`;
          clone.querySelector('[data-field="icon"]').alt = icon;
        }

        let targetPath = removeTrailingSlash(path) + "/" + filename;
        clone.onclick = () => {
          let is_dir_like = DIR_LIKE_FILES.includes(extension);

          if ((info.is_dir || is_dir_like) && !(extractingDmg && is_dir_like)) {
            navigateTo(targetPath);
          }

          if (!info.is_dir && !is_dir_like) {
            setSelectedFile(clone);
            showFilePreview(info, path);
          }
        };

        clone.oncontextmenu = (event) => {
          event.preventDefault();
          if (!isMouse) return;
          openContextMenu(info, event.clientX, event.clientY, path, clone);
        };

        let holdTimeout = null;
        clone.onpointerdown = (event) => {
          if (isMouse) return;
          holdTimeout = setTimeout(() => {
            openContextMenu(info, event.clientX, event.clientY, path, clone);
          }, MOBILE_CONTEXT_MENU_HOLD_SECONDS * 1000);
        };
        clone.onpointerup = () => {
          if (isMouse) return;
          clearTimeout(holdTimeout);
        };
        clone.onpointerleave = () => {
          if (isMouse) return;
          clearTimeout(holdTimeout);
        };

        if (selectedFile && selectedFileParentPath == browsePath && filename == selectedFile.getAttribute("data-filename")) {
          selectedFile = clone;
          clone.classList.add("bg-slate-200", "dark:bg-zinc-700");
        }

        if (info.has_key === false) {
          const noKey = clone.querySelector('[data-field="no-key"]');
          noKey.classList.remove("hidden");
          noKey.onpointerenter = (event) => {
            const rect = noKey.getBoundingClientRect();

            tooltip.innerText = "No decryption key available";

            tooltip.style.top = `${rect.top - tooltip.offsetHeight - 2}px`;
            tooltip.style.left = `${rect.left - tooltip.offsetWidth / 2 + noKey.offsetWidth / 2}px`;

            tooltip.classList.remove("opacity-0");
            tooltip.classList.add("opacity-100", "visible");
          };
          noKey.onpointerleave = () => {
            tooltip.classList.remove("opacity-100", "visible");
            tooltip.classList.add("opacity-0");
          };
        }

        clone.removeAttribute("id");
        clone.setAttribute("data-filename", filename);
        clone.classList.remove("hidden");
        if (filename.includes(".dmg"))
          dmgInfo[filename] = {
            "size": info.size,
            "tag": tag
          };
        if (info.extracted && !extractedDmgs.includes(filename)) extractedDmgs.push(filename);
        container.appendChild(clone);
        if (!isSearchResults) listingElements.push(clone);
      }

      if (isSearchResults) {
        listingSearchBox.disabled = false;
        listingSearchClearButton.classList.remove("hidden");

        if (listing.length == 0) {
          listingSearchStatus.classList.remove("hidden");
          listingSearchStatus.innerText = "No results";
        } else {
          listingSearchStatus.classList.add("hidden");
        }
      }
    } else if (data.status == "dmginfo") {
      let filename = pathNeedsDmgExtraction(data.path);
      if (data.extracted) extractedDmgs.push(filename);
      dmgInfo[filename] = {
        "size": data.size,
        "tag": data.tag.split("|")[0]
      };
      navigateTo(data.path, false);
    }
  };

  window.onpopstate = (event) => {
    if (event.state && event.state.path) {
      navigateTo(event.state.path, false);
    } else {
      navigateTo("/", false);
    }
  };

  ws.onopen = () => {
    if (initializing) sendCommand("cache");

    heartbeat = setInterval(() => {
      sendCommand("ping");
    }, HEARTBEAT_INTERVAL);
  };

  ws.onerror = () => {
    listingPage.classList.add("invisible");
    initPage.classList.remove("invisible");
    initBar.classList.add("hidden");
    initStatus.innerText = "Error: Failed to connect to server";
    disconnected = true;
  };

  ws.onclose = () => {
    if (disconnected) return;
    clearInterval(heartbeat);
    listingPage.classList.add("invisible");
    initPage.classList.remove("invisible");
    initBar.classList.add("hidden");
    initStatus.innerText = "Disconnected from server, please refresh";
  };

  document.onpointerdown = (event) => {
    if (!contextMenu.contains(event.target)) dismissContextMenu();
  };

  listingSearchBox.onchange = (event) => {
    const query = event.target.value.trim();

    if (query == "") {
      listingSearchStatus.classList.add("hidden");
      listingFilesView.replaceChildren(...listingElements);
      listingSearchClearButton.classList.add("hidden");
      return;
    }

    listingFilesView.replaceChildren();
    listingSearchStatus.classList.remove("hidden");
    listingSearchStatus.innerText = "Searching...";
    listingSearchBox.disabled = true;

    sendCommand("search", {
      "path": browsePath,
      "query": query
    });
  };
  listingSearchClearButton.onclick = () => {
    listingSearchBox.value = "";
    listingSearchBox.dispatchEvent(new Event("change"));
  };
};