(function () {
  'use strict';

  var shell = document.querySelector('.m2-shell');
  if (!shell) return;

  var conversationId = Number(shell.getAttribute('data-conversation-id') || '0') || 0;
  var messages = document.getElementById('m2Messages');
  var search = document.getElementById('m2Search');
  var refreshButton = document.getElementById('m2RefreshButton');
  var menuButton = document.getElementById('m2MenuButton');
  var menu = document.getElementById('m2Menu');
  var emojiButton = document.getElementById('m2EmojiButton');
  var emojiPanel = document.getElementById('m2EmojiPanel');
  var attachButton = document.getElementById('m2AttachButton');
  var fileInput = document.getElementById('m2AttachmentInput');
  var preview = document.getElementById('m2AttachmentPreview');
  var recordButton = document.getElementById('m2RecordButton');
  var form = document.getElementById('m2Composer');
  var textarea = document.getElementById('m2Message');
  var recordedFile = null;
  var recorder = null;
  var stream = null;
  var chunks = [];
  var attachmentObjectUrl = '';

  if (conversationId > 0) {
    shell.classList.add('has-chat');
  }

  function stop(event) {
    if (!event) return;
    event.preventDefault();
    event.stopPropagation();
  }

  function closePanels() {
    if (menu) menu.classList.add('hidden');
    if (emojiPanel) emojiPanel.classList.add('hidden');
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
    window.requestAnimationFrame(scrollToLatest);
    [80, 220, 520, 1000].forEach(function (delay) {
      window.setTimeout(scrollToLatest, delay);
    });
  }

  function normalize(value) {
    return String(value || '').toLocaleLowerCase('pt-BR');
  }

  function autoResizeTextarea() {
    if (!textarea) return;
    textarea.style.height = 'auto';
    textarea.style.height = Math.min(textarea.scrollHeight, 118) + 'px';
  }

  function formatBytes(size) {
    var number = Number(size || 0);
    if (number < 1024) return number + ' B';
    if (number < 1024 * 1024) return (number / 1024).toFixed(1) + ' KB';
    return (number / (1024 * 1024)).toFixed(1) + ' MB';
  }

  function revokeAttachmentUrl() {
    if (attachmentObjectUrl) {
      URL.revokeObjectURL(attachmentObjectUrl);
      attachmentObjectUrl = '';
    }
  }

  function currentFile() {
    if (fileInput && fileInput.files && fileInput.files[0]) {
      return fileInput.files[0];
    }
    return recordedFile;
  }

  function clearAttachment() {
    revokeAttachmentUrl();
    recordedFile = null;
    if (fileInput) fileInput.value = '';
    if (preview) {
      preview.classList.add('hidden');
      preview.replaceChildren();
    }
  }

  function setFileInput(file) {
    recordedFile = file || null;
    if (!fileInput || !file) return;
    try {
      var transfer = new DataTransfer();
      transfer.items.add(file);
      fileInput.files = transfer.files;
    } catch (ignore) {
      recordedFile = file;
    }
  }

  function renderAttachmentPreview(file) {
    if (!preview) return;
    revokeAttachmentUrl();
    preview.replaceChildren();

    if (!file) {
      preview.classList.add('hidden');
      return;
    }

    preview.classList.remove('hidden');

    var card = document.createElement('div');
    card.className = 'm2-attachment-card';

    var media = null;
    if (file.type && file.type.indexOf('image/') === 0) {
      attachmentObjectUrl = URL.createObjectURL(file);
      media = document.createElement('img');
      media.src = attachmentObjectUrl;
      media.alt = file.name || 'Anexo';
    } else {
      media = document.createElement('span');
      media.innerHTML = file.type && file.type.indexOf('audio/') === 0
        ? '<i class="fa-solid fa-microphone"></i>'
        : '<i class="fa-solid fa-paperclip"></i>';
    }
    media.className = 'm2-attachment-thumb';

    var label = document.createElement('span');
    var strong = document.createElement('strong');
    var small = document.createElement('small');
    strong.textContent = file.name || 'Anexo pronto';
    small.textContent = formatBytes(file.size);
    label.append(strong, small);

    var remove = document.createElement('button');
    remove.type = 'button';
    remove.className = 'm2-attachment-remove';
    remove.setAttribute('aria-label', 'Remover anexo');
    remove.innerHTML = '<i class="fa-solid fa-xmark"></i>';
    remove.addEventListener('click', clearAttachment);

    card.append(media, label, remove);
    preview.append(card);
  }

  function insertAtCursor(text) {
    if (!textarea || textarea.disabled || !text) return;
    var start = typeof textarea.selectionStart === 'number' ? textarea.selectionStart : textarea.value.length;
    var end = typeof textarea.selectionEnd === 'number' ? textarea.selectionEnd : textarea.value.length;
    textarea.value = textarea.value.slice(0, start) + text + textarea.value.slice(end);
    var next = start + text.length;
    textarea.focus();
    try {
      textarea.setSelectionRange(next, next);
    } catch (ignore) {}
    autoResizeTextarea();
  }

  function renderEmojiPanel() {
    if (!emojiPanel) return;
    var emojis = [
      '\u{1F600}', '\u{1F602}', '\u{1F60D}', '\u{1F525}',
      '\u{1F44F}', '\u{1F64F}', '\u{1F44D}', '\u{1F440}',
      '\u{2705}', '\u{2764}\u{FE0F}', '\u{1F3AF}', '\u{1F4C5}',
      '\u{1F91D}', '\u{1F680}', '\u{1F4AA}', '\u{1F642}'
    ];
    emojiPanel.replaceChildren();
    emojis.forEach(function (emoji) {
      var button = document.createElement('button');
      button.type = 'button';
      button.setAttribute('data-emoji', emoji);
      button.textContent = emoji;
      emojiPanel.append(button);
    });
  }

  function stopStream() {
    if (!stream) return;
    stream.getTracks().forEach(function (track) {
      track.stop();
    });
    stream = null;
  }

  function setRecordingUi(active) {
    if (!recordButton) return;
    recordButton.classList.toggle('is-recording', active);
    recordButton.setAttribute('aria-label', active ? 'Parar gravacao' : 'Audio');
    recordButton.innerHTML = active
      ? '<i class="fa-solid fa-stop"></i>'
      : '<i class="fa-solid fa-microphone"></i>';
  }

  function preferredAudioMime() {
    if (!window.MediaRecorder || !MediaRecorder.isTypeSupported) return '';
    if (MediaRecorder.isTypeSupported('audio/ogg;codecs=opus')) return 'audio/ogg;codecs=opus';
    if (MediaRecorder.isTypeSupported('audio/webm;codecs=opus')) return 'audio/webm;codecs=opus';
    if (MediaRecorder.isTypeSupported('audio/webm')) return 'audio/webm';
    return '';
  }

  async function toggleRecording(event) {
    stop(event);
    if (textarea && textarea.disabled) return;

    if (recorder && recorder.state === 'recording') {
      recorder.stop();
      return;
    }

    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia || !window.MediaRecorder) {
      alert('Seu navegador nao liberou gravacao de audio aqui.');
      return;
    }

    try {
      stream = await navigator.mediaDevices.getUserMedia({ audio: true });
      chunks = [];
      var mime = preferredAudioMime();
      var activeRecorder = new MediaRecorder(stream, mime ? { mimeType: mime } : undefined);
      recorder = activeRecorder;

      activeRecorder.addEventListener('dataavailable', function (dataEvent) {
        if (dataEvent.data && dataEvent.data.size > 0) {
          chunks.push(dataEvent.data);
        }
      });

      activeRecorder.addEventListener('stop', function () {
        stopStream();
        setRecordingUi(false);
        var finalMime = activeRecorder.mimeType || mime || 'audio/webm';
        var extension = finalMime.indexOf('ogg') !== -1 || finalMime.indexOf('opus') !== -1 ? 'ogg' : 'webm';
        var blob = new Blob(chunks, { type: finalMime });
        var fileName = 'audio_' + Date.now() + '.' + extension;
        var file;
        try {
          file = new File([blob], fileName, { type: finalMime });
        } catch (ignore) {
          file = blob;
          file.name = fileName;
        }
        setFileInput(file);
        renderAttachmentPreview(file);
        recorder = null;
      });

      activeRecorder.start();
      setRecordingUi(true);
    } catch (error) {
      stopStream();
      setRecordingUi(false);
      alert('Nao foi possivel iniciar a gravacao.');
    }
  }

  async function sendForm(event) {
    stop(event);
    if (!form) return;

    var file = currentFile();
    var text = textarea ? textarea.value.trim() : '';
    if (!text && !file) {
      alert('Digite uma mensagem ou escolha um anexo.');
      return;
    }

    var submitButton = form.querySelector('button[type="submit"]');
    if (submitButton) submitButton.disabled = true;

    try {
      var body = new FormData(form);
      body.set('return_to_mobile', '1');
      body.set('return_to_workspace', '0');
      if (file) {
        body.set('media_file', file, file.name || 'audio.webm');
      }

      var response = await fetch(window.location.pathname + window.location.search, {
        method: 'POST',
        body: body,
        credentials: 'same-origin',
        headers: {
          Accept: 'application/json',
          'X-Requested-With': 'XMLHttpRequest'
        }
      });

      var data = null;
      var contentType = response.headers.get('content-type') || '';
      if (contentType.indexOf('application/json') !== -1) {
        data = await response.json();
      } else {
        var textResponse = await response.text();
        data = { ok: false, error: textResponse ? 'Resposta inesperada do servidor.' : '' };
      }

      if (!response.ok || !data || !data.ok) {
        throw new Error((data && data.error) || 'Nao foi possivel enviar.');
      }

      if (textarea) {
        textarea.value = '';
        autoResizeTextarea();
      }
      clearAttachment();
      window.location.reload();
    } catch (error) {
      alert(error.message || 'Nao foi possivel enviar.');
    } finally {
      if (submitButton) submitButton.disabled = false;
    }
  }

  async function transcribeAudio(button) {
    if (!button || button.getAttribute('data-busy') === '1') return;
    button.setAttribute('data-busy', '1');
    button.disabled = true;
    var oldHtml = button.innerHTML;
    button.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i>Transcrevendo';

    try {
      var response = await fetch('api/whatsapp_transcribe_audio_v2.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          'Content-Type': 'application/json',
          Accept: 'application/json'
        },
        body: JSON.stringify({
          conversation_id: conversationId,
          message_id: button.getAttribute('data-transcribe-audio') || '',
          media_url: button.getAttribute('data-media-url') || ''
        })
      });
      var data = await response.json().catch(function () { return null; });
      if (!response.ok || !data || !data.ok) {
        throw new Error((data && data.error) || 'Nao foi possivel transcrever.');
      }

      var bubble = button.closest('.m2-bubble');
      if (bubble) {
        var box = bubble.querySelector('.m2-transcript');
        if (!box) {
          box = document.createElement('div');
          box.className = 'm2-transcript';
          var time = bubble.querySelector('time');
          if (time) {
            bubble.insertBefore(box, time);
          } else {
            bubble.append(box);
          }
        }
        box.textContent = data.text || 'Transcricao concluida.';
      }
      button.innerHTML = '<i class="fa-solid fa-check"></i>Transcrito';
    } catch (error) {
      alert(error.message || 'Nao foi possivel transcrever.');
      button.innerHTML = oldHtml;
      button.disabled = false;
    } finally {
      button.setAttribute('data-busy', '0');
    }
  }

  renderEmojiPanel();
  scheduleScroll();

  if (messages) {
    messages.querySelectorAll('img, video').forEach(function (media) {
      media.addEventListener('load', scheduleScroll);
      media.addEventListener('loadedmetadata', scheduleScroll);
    });
  }

  if (refreshButton) {
    refreshButton.addEventListener('click', function (event) {
      stop(event);
      window.location.reload();
    });
  }

  if (search) {
    search.addEventListener('input', function () {
      var query = normalize(search.value);
      document.querySelectorAll('.m2-item').forEach(function (item) {
        var haystack = normalize(item.getAttribute('data-search'));
        item.hidden = query !== '' && haystack.indexOf(query) === -1;
      });
    });
  }

  if (menuButton && menu) {
    menuButton.addEventListener('click', function (event) {
      stop(event);
      if (emojiPanel) emojiPanel.classList.add('hidden');
      menu.classList.toggle('hidden');
    });
  }

  if (emojiButton && emojiPanel) {
    emojiButton.addEventListener('click', function (event) {
      stop(event);
      if (menu) menu.classList.add('hidden');
      emojiPanel.classList.toggle('hidden');
    });
    emojiPanel.addEventListener('click', function (event) {
      var button = event.target.closest('[data-emoji]');
      if (!button) return;
      stop(event);
      insertAtCursor(button.getAttribute('data-emoji') || button.textContent || '');
    });
  }

  if (attachButton && fileInput) {
    attachButton.addEventListener('click', function (event) {
      stop(event);
      if (textarea && textarea.disabled) return;
      fileInput.click();
    });
    fileInput.addEventListener('change', function () {
      recordedFile = null;
      renderAttachmentPreview(currentFile());
    });
  }

  if (recordButton) {
    recordButton.addEventListener('click', toggleRecording);
  }

  if (textarea) {
    textarea.addEventListener('input', autoResizeTextarea);
    autoResizeTextarea();
  }

  if (form) {
    form.addEventListener('submit', sendForm);
  }

  document.addEventListener('click', function (event) {
    var transcribeButton = event.target.closest('[data-transcribe-audio]');
    if (transcribeButton) {
      stop(event);
      transcribeAudio(transcribeButton);
      return;
    }

    if (menu && !menu.classList.contains('hidden')) {
      if (!event.target.closest('#m2Menu') && !event.target.closest('#m2MenuButton')) {
        menu.classList.add('hidden');
      }
    }
  });

  document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape') {
      closePanels();
    }
  });

  window.addEventListener('load', scheduleScroll);
  window.addEventListener('beforeunload', function () {
    revokeAttachmentUrl();
    stopStream();
  });
})();
