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
  var openToolsButton = document.getElementById('m2OpenTools');
  var toolsPanel = document.getElementById('m2ToolsPanel');
  var openAppointmentButton = document.getElementById('m2OpenAppointment');
  var appointmentPanel = document.getElementById('m2AppointmentPanel');
  var openAppointmentFromTools = document.getElementById('m2OpenAppointmentFromTools');
  var csrfToken = document.querySelector('input[name="csrf_token"]')?.value || '';
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
    if (toolsPanel) toolsPanel.classList.add('hidden');
    if (appointmentPanel) appointmentPanel.classList.add('hidden');
  }

  function openDrawer(drawer) {
    if (!drawer) return;
    if (toolsPanel && toolsPanel !== drawer) toolsPanel.classList.add('hidden');
    if (appointmentPanel && appointmentPanel !== drawer) appointmentPanel.classList.add('hidden');
    if (menu) menu.classList.add('hidden');
    if (emojiPanel) emojiPanel.classList.add('hidden');
    drawer.classList.remove('hidden');
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
    if (recordedFile) {
      return recordedFile;
    }
    if (fileInput && fileInput.files && fileInput.files[0]) {
      return fileInput.files[0];
    }
    return null;
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
    if (!fileInput) return;
    if (!file) {
      fileInput.value = '';
      return;
    }
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

  function cleanMime(value) {
    return String(value || '').split(';')[0].trim() || 'audio/webm';
  }

  async function toggleRecording(event) {
    stop(event);
    if (textarea && textarea.disabled) return;

    if (recorder && recorder.state === 'recording') {
      try {
        recorder.requestData();
      } catch (ignore) {}
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
        if (!chunks.length) {
          recorder = null;
          alert('A gravacao ficou vazia. Tente gravar novamente.');
          return;
        }
        var finalMime = cleanMime(activeRecorder.mimeType || mime || 'audio/webm');
        var extension = finalMime.indexOf('ogg') !== -1 ? 'ogg' : (finalMime.indexOf('mp4') !== -1 || finalMime.indexOf('m4a') !== -1 ? 'm4a' : 'webm');
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

      activeRecorder.start(1000);
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
    form.setAttribute('data-sending', '1');

    try {
      var body = new FormData(form);
      body.set('return_to_mobile', '1');
      body.set('return_to_mobile2', '1');
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

      var rawResponse = await response.text();
      var data = null;
      if (rawResponse) {
        try {
          data = JSON.parse(rawResponse);
        } catch (ignore) {
          data = null;
        }
      }
      if (!data && response.ok) {
        data = { ok: true };
      }

      if (!response.ok || !data || !data.ok) {
        throw new Error((data && data.error) || 'Nao foi possivel enviar.');
      }

      if (textarea) {
        textarea.value = '';
        autoResizeTextarea();
      }
      clearAttachment();
      form.setAttribute('data-sending', '0');
      await refreshMessages(true);
      window.setTimeout(function () {
        refreshMessages(true);
      }, 900);
    } catch (error) {
      alert(error.message || 'Nao foi possivel enviar.');
    } finally {
      form.setAttribute('data-sending', '0');
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

  function escapeHtml(value) {
    return String(value ?? '').replace(/[&<>"']/g, function (char) {
      return {
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#39;'
      }[char] || char;
    });
  }

  function inferMediaKind(mime, url, type) {
    var mediaMime = String(mime || '').toLowerCase();
    var mediaUrl = String(url || '').toLowerCase();
    var messageType = String(type || '').toLowerCase();
    if (mediaMime.indexOf('image/') === 0 || /\.(png|jpe?g|gif|webp)(\?|$)/i.test(mediaUrl) || messageType === 'image') return 'image';
    if (mediaMime.indexOf('video/') === 0 || /\.(mp4|webm|mov)(\?|$)/i.test(mediaUrl) || messageType === 'video') return 'video';
    if (mediaMime.indexOf('audio/') === 0 || /\.(ogg|mp3|wav|m4a|webm)(\?|$)/i.test(mediaUrl) || messageType === 'audio') return 'audio';
    return 'file';
  }

  function formatMessageTime(value) {
    if (!value) return '';
    var date = new Date(String(value).replace(' ', 'T'));
    if (Number.isNaN(date.getTime())) return String(value);
    return date.toLocaleString('pt-BR', {
      day: '2-digit',
      month: '2-digit',
      hour: '2-digit',
      minute: '2-digit'
    });
  }

  function keyForMessage(message) {
    return String(message?.id || message?.message_id || message?.sent_at || '');
  }

  function renderMessage(message) {
    var direction = String(message?.direction || 'in') === 'out' ? 'out' : 'in';
    var body = String(message?.body || '');
    var mediaUrl = String(message?.media_url || '');
    var mediaName = String(message?.media_file_name || '');
    var mediaKind = inferMediaKind(message?.media_mime, mediaUrl, message?.message_type);
    var transcript = String(message?.transcricao || message?.transcript || '').trim();
    var bubbleClass = 'm2-bubble' + (mediaKind === 'audio' ? ' m2-audio-bubble' : '');
    var html = '<article class="m2-msg ' + direction + '" data-message-key="' + escapeHtml(keyForMessage(message)) + '"><div class="' + bubbleClass + '">';

    if (mediaUrl) {
      if (!mediaName) {
        mediaName = decodeURIComponent((mediaUrl.split('/').pop() || '').split('?')[0] || '');
      }
      if (mediaKind === 'image') {
        html += '<img class="m2-media" src="' + escapeHtml(mediaUrl) + '" alt="' + escapeHtml(mediaName || 'Midia') + '">';
      } else if (mediaKind === 'video') {
        html += '<video class="m2-media" src="' + escapeHtml(mediaUrl) + '" controls></video>';
      } else if (mediaKind === 'audio') {
        html += '<audio class="m2-audio-player" src="' + escapeHtml(mediaUrl) + '" controls preload="metadata"></audio>';
        if (!transcript) {
          html += '<button class="m2-transcribe" type="button" data-transcribe-audio="' + escapeHtml(message?.message_id || '') + '" data-media-url="' + escapeHtml(mediaUrl) + '"><i class="fa-solid fa-wave-square"></i>Transcrever</button>';
        }
      } else {
        html += '<a class="m2-file" href="' + escapeHtml(mediaUrl) + '" target="_blank" rel="noopener"><i class="fa-solid fa-paperclip"></i>' + escapeHtml(mediaName || 'Abrir anexo') + '</a>';
      }
    }

    if (body) {
      html += '<p>' + escapeHtml(body).replace(/\n/g, '<br>') + '</p>';
    } else if (!mediaUrl && String(message?.message_type || '') !== 'texto') {
      html += '<p>[' + escapeHtml(message?.message_type || 'mensagem') + ']</p>';
    }
    if (transcript) {
      html += '<div class="m2-transcript">' + escapeHtml(transcript) + '</div>';
    }
    html += '<time>' + escapeHtml(formatMessageTime(message?.sent_at || '')) + '</time></div></article>';
    return html;
  }

  function latestRenderedKey() {
    if (!messages || !messages.lastElementChild) return '';
    return messages.lastElementChild.getAttribute('data-message-key') || '';
  }

  async function refreshMessages(force) {
    if (!messages || !conversationId || document.hidden) return;
    if (form && form.getAttribute('data-sending') === '1') return;

    var nearBottom = (messages.scrollHeight - messages.scrollTop - messages.clientHeight) < 180;
    try {
      var url = window.location.pathname + '?page=studio_whatsapp_mobile_api&action=messages&id=' + encodeURIComponent(String(conversationId));
      var response = await fetch(url, {
        credentials: 'same-origin',
        headers: {
          Accept: 'application/json',
          'X-Requested-With': 'XMLHttpRequest'
        }
      });
      if (!response.ok) return;
      var data = await response.json().catch(function () { return null; });
      if (!data || !data.ok || !Array.isArray(data.messages)) return;

      var latest = data.messages.length ? keyForMessage(data.messages[data.messages.length - 1]) : '';
      if (!force && latest && latest === messages.dataset.latestKey) return;
      messages.innerHTML = data.messages.map(renderMessage).join('');
      messages.dataset.latestKey = latest;
      if (nearBottom || force) {
        scheduleScroll();
      }
    } catch (ignore) {}
  }

  async function postConversationUpdate(payload, message) {
    var body = new URLSearchParams();
    body.set('csrf_token', csrfToken);
    body.set('action', 'update_whatsapp_profile');
    body.set('conversation_id', String(conversationId));
    body.set('return_to_mobile2', '1');
    Object.keys(payload || {}).forEach(function (key) {
      body.set(key, String(payload[key]));
    });

    var response = await fetch(window.location.pathname + window.location.search, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        Accept: 'application/json, text/plain, */*',
        'X-Requested-With': 'XMLHttpRequest'
      },
      body: body
    });

    if (!response.ok) {
      throw new Error(message || 'Nao foi possivel atualizar.');
    }
    window.location.reload();
  }

  renderEmojiPanel();
  if (messages) {
    messages.dataset.latestKey = latestRenderedKey();
  }
  scheduleScroll();
  window.setTimeout(function () {
    refreshMessages(true);
  }, 1200);
  window.setInterval(function () {
    refreshMessages(false);
  }, 5000);

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
      if (toolsPanel) toolsPanel.classList.add('hidden');
      if (appointmentPanel) appointmentPanel.classList.add('hidden');
      emojiPanel.classList.toggle('hidden');
    });
    emojiPanel.addEventListener('click', function (event) {
      var button = event.target.closest('[data-emoji]');
      if (!button) return;
      stop(event);
      insertAtCursor(button.getAttribute('data-emoji') || button.textContent || '');
    });
  }

  if (openToolsButton) {
    openToolsButton.addEventListener('click', function (event) {
      stop(event);
      openDrawer(toolsPanel);
    });
  }

  if (openAppointmentButton) {
    openAppointmentButton.addEventListener('click', function (event) {
      stop(event);
      openDrawer(appointmentPanel);
    });
  }

  if (openAppointmentFromTools) {
    openAppointmentFromTools.addEventListener('click', function (event) {
      stop(event);
      openDrawer(appointmentPanel);
    });
  }

  var copyPublicUrl = document.getElementById('m2CopyPublicUrl');
  if (copyPublicUrl) {
    copyPublicUrl.addEventListener('click', async function (event) {
      stop(event);
      var input = document.getElementById('m2PublicUpdateUrl');
      if (!input) return;
      try {
        await navigator.clipboard.writeText(input.value);
        copyPublicUrl.textContent = 'Copiado';
      } catch (error) {
        input.select();
        document.execCommand('copy');
        copyPublicUrl.textContent = 'Copiado';
      }
    });
  }

  var transcribePending = document.getElementById('m2TranscribePending');
  if (transcribePending) {
    transcribePending.addEventListener('click', function (event) {
      stop(event);
      var buttons = Array.prototype.slice.call(document.querySelectorAll('[data-transcribe-audio]'));
      buttons.forEach(function (button, index) {
        window.setTimeout(function () {
          transcribeAudio(button);
        }, index * 350);
      });
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
    var closeButton = event.target.closest('[data-close-drawer]');
    if (closeButton) {
      stop(event);
      var drawer = document.getElementById(closeButton.getAttribute('data-close-drawer') || '');
      if (drawer) drawer.classList.add('hidden');
      return;
    }

    var replyButton = event.target.closest('[data-reply]');
    if (replyButton) {
      stop(event);
      insertAtCursor(replyButton.getAttribute('data-reply') || '');
      return;
    }

    var modeButton = event.target.closest('[data-mode-toggle]');
    if (modeButton) {
      stop(event);
      var isBot = modeButton.getAttribute('data-mode-toggle') === 'bot';
      postConversationUpdate({
        attendance_mode: isBot ? 'bot' : 'human',
        needs_human: isBot ? '0' : '1',
        ai_last_status: isBot ? 'IA pronta' : 'IA inativa'
      }, 'Nao foi possivel atualizar o atendimento.').catch(function (error) {
        alert(error.message || 'Nao foi possivel atualizar o atendimento.');
      });
      return;
    }

    var statusButton = event.target.closest('[data-status-set]');
    if (statusButton) {
      stop(event);
      postConversationUpdate({
        status: statusButton.getAttribute('data-status-set') || 'novo',
        create_lead: '1'
      }, 'Nao foi possivel atualizar o status.').catch(function (error) {
        alert(error.message || 'Nao foi possivel atualizar o status.');
      });
      return;
    }

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
