(function () {
  'use strict';

  function byId(id) {
    return document.getElementById(id);
  }

  function prevent(event) {
    if (!event) return;
    event.preventDefault();
    if (event.stopImmediatePropagation) {
      event.stopImmediatePropagation();
    } else {
      event.stopPropagation();
    }
  }

  function initMobileWhatsapp() {
    var form = byId('mobileChatComposer');
    var textarea = byId('mobileReplyMessage');
    var fileInput = byId('mobileAttachmentInput');
    var fileName = byId('mobileAttachmentName');
    var attachButton = byId('mobileOpenAttachmentPicker');
    var pickButton = byId('mobileAttachmentPickAction');
    var recordButton = byId('mobileRecordAudioButton');
    var messages = byId('mobileWaMessages');
    var emojiPanel = byId('mobileEmojiPanel');
    var selectedFile = null;
    var recordedFile = null;
    var recordedFileName = '';
    var recorder = null;
    var stream = null;
    var chunks = [];
    var lastTouchAt = 0;

    if (!form) return;
    form.setAttribute('data-mobile-controller', 'asset-v1');

    function setAttachmentName(name) {
      if (fileName) {
        fileName.textContent = name || 'Nenhum arquivo selecionado';
      }
    }

    function scrollToLatest() {
      if (!messages) return;
      messages.scrollTop = messages.scrollHeight;
      if (messages.lastElementChild && messages.lastElementChild.scrollIntoView) {
        messages.lastElementChild.scrollIntoView({ block: 'end', inline: 'nearest' });
      }
    }

    function scheduleScroll() {
      scrollToLatest();
      setTimeout(scrollToLatest, 80);
      setTimeout(scrollToLatest, 250);
      setTimeout(scrollToLatest, 700);
    }

    function openAttachment(event) {
      prevent(event);
      if (fileInput) fileInput.click();
    }

    function togglePanelById(id) {
      var panel = byId(id);
      if (!panel) return;
      panel.classList.toggle('hidden');
    }

    function onFileChange() {
      selectedFile = fileInput && fileInput.files && fileInput.files[0] ? fileInput.files[0] : null;
      if (selectedFile) {
        recordedFile = null;
        recordedFileName = '';
      }
      setAttachmentName(selectedFile ? selectedFile.name : '');
    }

    function stopStream() {
      if (!stream) return;
      var tracks = stream.getTracks ? stream.getTracks() : [];
      for (var i = 0; i < tracks.length; i += 1) {
        tracks[i].stop();
      }
      stream = null;
    }

    function setRecordedFile(file, name) {
      recordedFile = file;
      recordedFileName = name || (file && file.name ? file.name : 'audio.webm');
      selectedFile = null;
      setAttachmentName(recordedFileName);
      try {
        if (fileInput && window.DataTransfer) {
          var dt = new DataTransfer();
          dt.items.add(file);
          fileInput.files = dt.files;
        }
      } catch (ignore) {}
    }

    function setRecordingUi(active) {
      if (!recordButton) return;
      if (active) {
        recordButton.classList.add('recording');
        recordButton.innerHTML = '<i class="fa-solid fa-stop"></i><span>Parar</span>';
      } else {
        recordButton.classList.remove('recording');
        recordButton.innerHTML = '<i class="fa-solid fa-microphone"></i><span>Audio</span>';
      }
    }

    function toggleRecording(event) {
      prevent(event);
      if (recorder && recorder.state === 'recording') {
        recorder.stop();
        return;
      }
      if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia || !window.MediaRecorder) {
        alert('Seu navegador nao liberou gravacao de audio aqui.');
        return;
      }
      navigator.mediaDevices.getUserMedia({ audio: true }).then(function (mediaStream) {
        stream = mediaStream;
        chunks = [];
        var mime = '';
        if (MediaRecorder.isTypeSupported && MediaRecorder.isTypeSupported('audio/webm;codecs=opus')) {
          mime = 'audio/webm;codecs=opus';
        } else if (MediaRecorder.isTypeSupported && MediaRecorder.isTypeSupported('audio/ogg;codecs=opus')) {
          mime = 'audio/ogg;codecs=opus';
        }
        recorder = new MediaRecorder(stream, mime ? { mimeType: mime } : undefined);
        recorder.ondataavailable = function (dataEvent) {
          if (dataEvent.data && dataEvent.data.size > 0) {
            chunks.push(dataEvent.data);
          }
        };
        recorder.onstop = function () {
          stopStream();
          var finalMime = recorder.mimeType || mime || 'audio/webm';
          var ext = finalMime.indexOf('ogg') !== -1 || finalMime.indexOf('opus') !== -1 ? 'ogg' : 'webm';
          var fileNameValue = 'audio_' + Date.now() + '.' + ext;
          var blob = new Blob(chunks, { type: finalMime });
          var file = blob;
          try {
            file = new File([blob], fileNameValue, { type: finalMime });
          } catch (ignore) {}
          setRecordedFile(file, fileNameValue);
          setRecordingUi(false);
        };
        recorder.start();
        setRecordingUi(true);
      }).catch(function () {
        stopStream();
        setRecordingUi(false);
        alert('Nao foi possivel iniciar a gravacao.');
      });
    }

    function onRecordTouch(event) {
      lastTouchAt = Date.now();
      toggleRecording(event);
    }

    function onRecordClick(event) {
      if (Date.now() - lastTouchAt < 650) {
        prevent(event);
        return;
      }
      toggleRecording(event);
    }

    function appendEmoji(event) {
      prevent(event);
      var target = event.target;
      while (target && target !== emojiPanel && !target.getAttribute('data-reply')) {
        target = target.parentNode;
      }
      if (!target || target === emojiPanel) return;
      var emoji = target.getAttribute('data-reply') || target.textContent || '';
      if (!textarea || !emoji) return;
      var start = typeof textarea.selectionStart === 'number' ? textarea.selectionStart : textarea.value.length;
      var end = typeof textarea.selectionEnd === 'number' ? textarea.selectionEnd : textarea.value.length;
      textarea.value = textarea.value.slice(0, start) + emoji + textarea.value.slice(end);
      var next = start + emoji.length;
      try {
        textarea.focus();
        textarea.setSelectionRange(next, next);
      } catch (ignore) {}
    }

    function sendForm(event) {
      prevent(event);
      var body = new FormData(form);
      body.set('return_to_mobile', '1');
      body.set('return_to_workspace', '0');

      selectedFile = fileInput && fileInput.files && fileInput.files[0] ? fileInput.files[0] : selectedFile;
      if (selectedFile) {
        body.set('media_file', selectedFile, selectedFile.name);
      }
      if (recordedFile) {
        body.set('media_file', recordedFile, recordedFile.name || recordedFileName || 'audio.webm');
      }

      var submitButton = form.querySelector('[type="submit"]');
      if (submitButton) submitButton.disabled = true;

      var xhr = new XMLHttpRequest();
      xhr.open('POST', window.location.pathname + window.location.search, true);
      xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
      xhr.setRequestHeader('Accept', 'application/json');
      xhr.onreadystatechange = function () {
        if (xhr.readyState !== 4) return;
        if (submitButton) submitButton.disabled = false;
        var data = null;
        try {
          data = JSON.parse(xhr.responseText || '{}');
        } catch (ignore) {}
        if (xhr.status < 200 || xhr.status >= 300 || !data || data.ok === false) {
          alert((data && data.error) || 'Nao foi possivel enviar a mensagem.');
          return;
        }
        if (textarea) textarea.value = '';
        if (fileInput) fileInput.value = '';
        selectedFile = null;
        recordedFile = null;
        recordedFileName = '';
        setAttachmentName('');
        window.location.reload();
      };
      xhr.send(body);
    }

    if (attachButton) {
      attachButton.addEventListener('click', openAttachment, true);
      attachButton.addEventListener('touchend', openAttachment, true);
    }
    var emojiToggle = byId('mobileOpenEmojiPanel');
    if (emojiToggle) {
      emojiToggle.addEventListener('click', function (event) {
        prevent(event);
        togglePanelById('mobileEmojiPanel');
      }, true);
      emojiToggle.addEventListener('touchend', function (event) {
        prevent(event);
        togglePanelById('mobileEmojiPanel');
      }, true);
    }
    var scheduleToggle = byId('mobileOpenSchedule');
    if (scheduleToggle) {
      scheduleToggle.addEventListener('click', function (event) {
        prevent(event);
        togglePanelById('mobileSchedulePanel');
      }, true);
      scheduleToggle.addEventListener('touchend', function (event) {
        prevent(event);
        togglePanelById('mobileSchedulePanel');
      }, true);
    }
    var quickMenuButton = byId('mobileQuickMenuButton');
    var bottomActionsButton = byId('mobileBottomActionsButton');
    var refreshButton = byId('mobileRefreshButton');
    var globalRefreshButton = byId('mobileGlobalRefreshAction');
    if (quickMenuButton) {
      quickMenuButton.addEventListener('click', function (event) {
        prevent(event);
        togglePanelById('mobileConversationMenu');
      }, true);
    }
    if (bottomActionsButton) {
      bottomActionsButton.addEventListener('click', function (event) {
        prevent(event);
        togglePanelById('mobileGlobalActionsPanel');
      }, true);
    }
    if (refreshButton) {
      refreshButton.addEventListener('click', function (event) {
        prevent(event);
        window.location.reload();
      }, true);
    }
    if (globalRefreshButton) {
      globalRefreshButton.addEventListener('click', function (event) {
        prevent(event);
        window.location.reload();
      }, true);
    }
    if (pickButton) {
      pickButton.addEventListener('click', openAttachment, true);
      pickButton.addEventListener('touchend', openAttachment, true);
    }
    if (fileInput) {
      fileInput.addEventListener('change', onFileChange);
    }
    if (recordButton) {
      recordButton.addEventListener('touchend', onRecordTouch, true);
      recordButton.addEventListener('click', onRecordClick, true);
    }
    if (emojiPanel) {
      emojiPanel.addEventListener('click', appendEmoji, true);
      emojiPanel.addEventListener('touchend', appendEmoji, true);
    }
    var emojiToggle = byId('mobileOpenEmojiPanel');
    if (emojiToggle) {
      emojiToggle.addEventListener('click', function (event) {
        prevent(event);
        togglePanelById('mobileEmojiPanel');
      }, true);
      emojiToggle.addEventListener('touchend', function (event) {
        prevent(event);
        togglePanelById('mobileEmojiPanel');
      }, true);
    }
    var openAttachment = byId('mobileOpenAttachmentPicker');
    if (openAttachment) {
      openAttachment.addEventListener('click', function (event) {
        prevent(event);
        if (fileInput) fileInput.click();
      }, true);
      openAttachment.addEventListener('touchend', function (event) {
        prevent(event);
        if (fileInput) fileInput.click();
      }, true);
    }
    var scheduleToggle = byId('mobileOpenSchedule');
    if (scheduleToggle) {
      scheduleToggle.addEventListener('click', function (event) {
        prevent(event);
        togglePanelById('mobileSchedulePanel');
      }, true);
      scheduleToggle.addEventListener('touchend', function (event) {
        prevent(event);
        togglePanelById('mobileSchedulePanel');
      }, true);
    }

    document.querySelectorAll('.mobile-wa-action, .mobile-wa-btn, .mobile-wa-icon-btn, .nav-chip').forEach(function (button) {
      button.addEventListener('touchstart', function () {
        button.classList.add('is-touched');
      }, { passive: true });
      button.addEventListener('touchend', function () {
        button.classList.remove('is-touched');
      }, { passive: true });
    });
    form.addEventListener('submit', sendForm, true);

    scheduleScroll();
    window.addEventListener('load', scheduleScroll);
    if (messages && window.MutationObserver) {
      new MutationObserver(scheduleScroll).observe(messages, { childList: true, subtree: true });
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initMobileWhatsapp);
  } else {
    initMobileWhatsapp();
  }
})();
