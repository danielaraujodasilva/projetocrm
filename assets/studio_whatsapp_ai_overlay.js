(function () {
    "use strict";

    function $(id) {
        return document.getElementById(id);
    }

    function escapeHtml(value) {
        return String(value ?? "").replace(/[&<>"']/g, (char) => ({
            "&": "&amp;",
            "<": "&lt;",
            ">": "&gt;",
            '"': "&quot;",
            "'": "&#39;",
        }[char] || char));
    }

    function copyText(value) {
        const text = String(value ?? "");
        if (!text) return Promise.resolve(false);
        if (navigator.clipboard?.writeText) {
            return navigator.clipboard.writeText(text).then(() => true).catch(() => false);
        }
        const textarea = document.createElement("textarea");
        textarea.value = text;
        textarea.setAttribute("readonly", "readonly");
        textarea.style.position = "fixed";
        textarea.style.left = "-9999px";
        document.body.appendChild(textarea);
        textarea.select();
        let ok = false;
        try {
            ok = document.execCommand("copy");
        } catch (error) {
            ok = false;
        }
        textarea.remove();
        return Promise.resolve(ok);
    }

    function readJson(id) {
        const node = $(id);
        if (!node) return null;
        try {
            return JSON.parse(node.textContent || "{}");
        } catch (error) {
            return null;
        }
    }

    function getCsrfToken() {
        return document.querySelector('input[name="csrf_token"]')?.value || "";
    }

    function getComposerTextarea(selector) {
        const el = document.querySelector(selector);
        return el instanceof HTMLTextAreaElement ? el : null;
    }

    function renderBadge(label, tone) {
        return `<span class="badge ${tone || ""}">${escapeHtml(label)}</span>`;
    }

    function renderRow(label, value) {
        const text = String(value ?? "").trim();
        return `<div class="ai-row"><strong>${escapeHtml(label)}</strong><span>${escapeHtml(text || "Nao informado")}</span></div>`;
    }

    function hasText(value) {
        return String(value ?? "").trim() !== "";
    }

    function renderPanel(data, options) {
        const body = options.body;
        if (!body) return;
        body.style.cssText = "display:grid;gap:14px;padding:20px;background:linear-gradient(180deg,rgba(17,27,33,.98) 0%,rgba(12,19,24,.99) 100%);color:#e9edef;border-radius:14px;box-sizing:border-box";

        const summary = String(data?.summary || "").trim();
        const suggestedReply = String(data?.suggested_reply || "").trim();
        const aiEnabled = !!data?.ai_enabled;
        const confidence = Number(data?.confidence || 0);
        const source = String(data?.source || "heuristic");
        const needsHuman = !!data?.needs_human;
        const signalCount = Number(data?.signal_count || 0);
        const analysisStage = String(data?.analysis_stage || (aiEnabled ? "avaliando" : "desativada"));
        const analysisLabel = String(data?.analysis_label || (aiEnabled ? "Avaliando conversa" : "IA desativada"));
        const analysisDetail = String(data?.analysis_detail || "");
        const suggestedDate = String(data?.suggested_date || "").trim();
        const suggestedTime = String(data?.suggested_time || "").trim();
        const scheduleReason = String(data?.schedule_reason || "").trim();
        const nextBestAction = String(data?.next_best_action || "").trim();
        const missingFields = Array.isArray(data?.missing_fields) ? data.missing_fields : [];
        const commercialSignals = Array.isArray(data?.commercial_signals) ? data.commercial_signals : [];
        const riskFlags = Array.isArray(data?.risk_flags) ? data.risk_flags : [];
        const customerMood = String(data?.customer_mood || "neutro");
        const audioCount = Number(data?.audio_count || 0);
        const transcribedAudioCount = Number(data?.transcribed_audio_count || 0);
        const messageCount = Number(data?.message_count || 0);
        const contextLabel = [data?.current_name || data?.suggested_name || "", data?.phone || ""].filter(Boolean).join(" • ");
        const notice = String(options?.notice || "").trim();
        const confidencePct = Math.max(0, Math.min(100, Math.round(confidence * 10)));
        const statusTone = analysisStage === "pronta" ? "ok" : (analysisStage === "desativada" ? "warn" : "neutral");
        const statusText = analysisStage === "pronta" ? "Pronta" : (analysisStage === "parcial" ? "Parcial" : (analysisStage === "sem_contexto" ? "Sem contexto" : (analysisStage === "desativada" ? "Desativada" : "Avaliando")));
        const suggestionCards = [];
        if (hasText(nextBestAction)) suggestionCards.push(`<div class="ai-box ai-box-action"><strong>Proxima melhor acao</strong><p>${escapeHtml(nextBestAction)}</p></div>`);
        if (hasText(summary)) suggestionCards.push(`<div class="ai-box"><strong>Resumo</strong><p>${escapeHtml(summary)}</p></div>`);
        if (hasText(data?.suggested_name)) suggestionCards.push(`<div class="ai-box"><strong>Nome sugerido</strong><span>${escapeHtml(data.suggested_name)}</span></div>`);
        if (hasText(data?.suggested_interest)) suggestionCards.push(`<div class="ai-box"><strong>Interesse sugerido</strong><span>${escapeHtml(data.suggested_interest)}</span></div>`);
        if (hasText(suggestedDate) || hasText(suggestedTime)) suggestionCards.push(`<div class="ai-box"><strong>Data e hora sugeridas</strong><span>${escapeHtml([suggestedDate, suggestedTime].filter(Boolean).join(" ") || "Nao sugerido")}</span></div>`);
        if (hasText(scheduleReason)) suggestionCards.push(`<div class="ai-box"><strong>Motivo do agendamento</strong><p>${escapeHtml(scheduleReason)}</p></div>`);
        if (hasText(data?.suggested_notes)) suggestionCards.push(`<div class="ai-box"><strong>Observacoes sugeridas</strong><p>${escapeHtml(data.suggested_notes)}</p></div>`);
        if (hasText(suggestedReply)) suggestionCards.push(`<div class="ai-box"><strong>Resposta sugerida</strong><div class="ai-reply">${escapeHtml(suggestedReply)}</div></div>`);
        if (!suggestionCards.length) suggestionCards.push(`<div class="ai-box"><strong>Sem sinais fortes ainda</strong><p class="muted">A conversa ainda não entregou contexto suficiente para uma leitura confiável.</p></div>`);

        body.innerHTML = `
            <style>
                .ai-shell{display:grid;gap:14px;color:#e9edef}
                .ai-hero{display:flex;justify-content:space-between;gap:12px;align-items:flex-start;flex-wrap:wrap;padding:16px;border-radius:14px;background:rgba(13,22,28,.92);border:1px solid rgba(0,168,132,.16)}
                .ai-hero h4{margin:0 0 6px;font-size:1rem}
                .ai-hero p{margin:0;color:#9aa7af;line-height:1.4}
                .ai-status{padding:14px 16px;border-radius:14px;background:rgba(255,255,255,.03);border:1px solid rgba(0,168,132,.14);display:grid;gap:10px}
                .ai-status-top{display:flex;justify-content:space-between;gap:10px;align-items:center;flex-wrap:wrap}
                .ai-status-top strong{font-size:.98rem}
                .ai-progress{height:9px;border-radius:999px;background:rgba(255,255,255,.08);overflow:hidden}
                .ai-progress > span{display:block;height:100%;border-radius:inherit;background:linear-gradient(90deg,#00a884,#12b886);width:${confidencePct}%}
                .ai-context{padding:10px 12px;border-radius:12px;background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.08);color:#c9d4da;font-size:.92rem}
                .ai-box{padding:14px;border-radius:14px;background:rgba(255,255,255,.03);border:1px solid rgba(255,255,255,.08);min-width:0}
                .ai-box-action{background:rgba(18,184,134,.10);border-color:rgba(18,184,134,.28)}
                .ai-box strong{display:block;font-size:.78rem;text-transform:uppercase;letter-spacing:.02em;opacity:.78;margin-bottom:6px;color:#9fb0b8}
                .ai-box span,.ai-box p{display:block;line-height:1.45;white-space:pre-wrap;overflow-wrap:anywhere;color:#e9edef}
                .ai-box .muted{color:#9aa7af}
                .ai-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px}
                .ai-chip-list{display:flex;flex-wrap:wrap;gap:8px}
                .ai-chip{display:inline-flex;align-items:center;min-height:28px;padding:6px 10px;border-radius:999px;background:rgba(255,255,255,.07);border:1px solid rgba(255,255,255,.09);font-size:.84rem;color:#d7dee2}
                .ai-chip.warn{background:rgba(255,193,7,.12);border-color:rgba(255,193,7,.22);color:#ffe08a}
                .ai-chip.ok{background:rgba(18,184,134,.10);border-color:rgba(18,184,134,.22);color:#a7f3d0}
                .ai-actions{display:flex;gap:8px;flex-wrap:wrap}
                .ai-actions button{border:0;border-radius:999px;padding:10px 14px;font-weight:600;cursor:pointer}
                .ai-actions .primary{background:#12b886;color:#08131d}
                .ai-actions .secondary{background:rgba(255,255,255,.08);color:inherit}
                .ai-actions .ghost{background:transparent;border:1px solid rgba(255,255,255,.12);color:inherit}
                .ai-warning{padding:12px 14px;border-radius:12px;background:rgba(255,193,7,.12);border:1px solid rgba(255,193,7,.24);color:#ffd87a}
                .ai-suggestions{display:grid;gap:10px}
                .ai-reply{padding:14px;border-radius:14px;background:rgba(6,13,17,.78);border:1px solid rgba(0,168,132,.14);white-space:pre-wrap;overflow-wrap:anywhere}
                .ai-empty{padding:18px;border-radius:14px;border:1px dashed rgba(255,255,255,.18);color:#9aa7af}
            </style>
            <div class="ai-shell">
                <div class="ai-hero">
                    <div>
                        <h4>Sugestoes da IA</h4>
                        <p>Leitura silenciosa da conversa atual, sem enviar mensagem automaticamente.</p>
                    </div>
                    <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
                        ${renderBadge(source === "ai" ? "IA" : "Heuristica", source === "ai" ? "ok" : "warn")}
                        ${renderBadge(statusText, statusTone)}
                        ${renderBadge(`${Math.max(0, Math.min(10, confidence))}/10`, "neutral")}
                        ${needsHuman ? renderBadge("Pede humano", "warn") : renderBadge("Sem alerta", "ok")}
                    </div>
                </div>
                <div class="ai-status">
                    <div class="ai-status-top">
                        <strong>${escapeHtml(analysisLabel)}</strong>
                        <span class="badge ${statusTone}">${escapeHtml(`${signalCount} sinais`)}</span>
                    </div>
                    <div class="ai-progress" aria-hidden="true"><span></span></div>
                    <div class="muted" style="color:#9aa7af">${escapeHtml(analysisDetail || "A IA ainda está organizando o contexto dessa conversa.")}</div>
                </div>
                <div class="ai-context">Conversa atual: ${escapeHtml(contextLabel || String(data?.conversation_id || ""))}</div>
                <div class="ai-grid">
                    <div class="ai-box"><strong>Humor</strong><span>${escapeHtml(customerMood)}</span></div>
                    <div class="ai-box"><strong>Mensagens</strong><span>${escapeHtml(String(messageCount))}</span></div>
                    <div class="ai-box"><strong>Audios transcritos</strong><span>${escapeHtml(`${transcribedAudioCount}/${audioCount}`)}</span></div>
                </div>
                <div class="ai-box">
                    <strong>Sinais comerciais</strong>
                    <div class="ai-chip-list">${commercialSignals.length ? commercialSignals.map((item) => `<span class="ai-chip ok">${escapeHtml(item)}</span>`).join("") : `<span class="muted">Nenhum sinal comercial forte ainda.</span>`}</div>
                </div>
                <div class="ai-box">
                    <strong>Campos faltando</strong>
                    <div class="ai-chip-list">${missingFields.length ? missingFields.map((item) => `<span class="ai-chip">${escapeHtml(item)}</span>`).join("") : `<span class="muted">Cadastro e contexto principais parecem suficientes.</span>`}</div>
                </div>
                <div class="ai-box">
                    <strong>Alertas</strong>
                    <div class="ai-chip-list">${riskFlags.length ? riskFlags.map((item) => `<span class="ai-chip warn">${escapeHtml(item)}</span>`).join("") : `<span class="muted">Sem alerta sensivel detectado.</span>`}</div>
                </div>

                ${notice ? `<div class="ai-warning">${escapeHtml(notice)}</div>` : ""}
                ${aiEnabled ? "" : `<div class="ai-warning">IA desativada nas configuracoes do estudio.</div>`}
                <div class="ai-suggestions">${suggestionCards.join("")}</div>

                <div class="ai-actions">
                    <button type="button" class="primary" data-ai-action="refresh">Atualizar sugestoes</button>
                    <button type="button" class="secondary" data-ai-action="copy-summary">Copiar resumo</button>
                    <button type="button" class="secondary" data-ai-action="copy-reply" ${suggestedReply ? "" : "disabled"}>Copiar resposta</button>
                    <button type="button" class="secondary" data-ai-action="fill-reply" ${suggestedReply ? "" : "disabled"}>Usar na mensagem</button>
                    <button type="button" class="ghost" data-ai-action="apply-profile">Aplicar no cadastro</button>
                </div>
            </div>
        `;
    }

    function makeProfilePayload(data, options) {
        const current = data || {};
        const source = options?.source || "workspace";
        const payload = new URLSearchParams();
        payload.set("csrf_token", getCsrfToken());
        payload.set("action", "update_whatsapp_profile");
        payload.set("conversation_id", String(current.conversation_id || ""));
        payload.set(source === "mobile" ? "return_to_mobile2" : "return_to_workspace", "1");
        payload.set("name", String(current.suggested_name || current.current_name || ""));
        payload.set("phone", String(current.phone || ""));
        payload.set("email", String(current.email || ""));
        payload.set("instagram", String(current.instagram || ""));
        payload.set("lead_score", String(current.lead_score || 0));
        payload.set("interest", String(current.suggested_interest || current.current_interest || ""));
        payload.set("status", String(current.lead_status || "em_conversa"));
        payload.set("pipeline_stage", String(current.lead_pipeline_stage || "em_conversa"));
        payload.set("notes", String(current.suggested_notes || current.current_notes || ""));
        payload.set("needs_human", current.needs_human ? "1" : "");
        payload.set("create_customer", "1");
        payload.set("create_lead", "1");
        if (current.customer_id) payload.set("customer_id", String(current.customer_id));
        if (current.lead_id) payload.set("lead_id", String(current.lead_id));
        if (current.lead_estimated_value) payload.set("estimated_value", String(current.lead_estimated_value));
        return payload;
    }

    function initPanel(config) {
        const trigger = $(config.triggerId);
        const modal = $(config.modalId);
        const body = $(config.bodyId);
        const close = $(config.closeId);
        const initialData = readJson(config.initialId) || {};
        const composer = getComposerTextarea(config.composerSelector);
        if (!trigger || !modal || !body) {
            return;
        }

        let currentData = initialData;
        let currentRequest = null;
        let draftText = "";

        function open() {
            draftText = composer ? composer.value : "";
            modal.classList.remove("hidden");
            renderPanel(currentData, { body });
            void refresh();
        }

        function closePanel() {
            modal.classList.add("hidden");
            if (composer) {
                composer.value = draftText;
            }
        }

        async function fetchSuggestions() {
            const payload = new URLSearchParams();
            payload.set("csrf_token", getCsrfToken());
            payload.set("action", "whatsapp_ai_suggestions");
            payload.set("conversation_id", String(currentData.conversation_id || initialData.conversation_id || ""));
            const response = await fetch(window.location.pathname + window.location.search, {
                method: "POST",
                headers: {
                    "X-Requested-With": "XMLHttpRequest",
                    "Accept": "application/json, text/plain, */*",
                },
                body: payload,
            });
            const data = await response.json().catch(() => null);
            if (!response.ok || !data || !data.ok) {
                throw new Error((data && data.error) || "Nao foi possivel atualizar as sugestoes.");
            }
            return data;
        }

        async function refresh() {
            if (currentRequest) {
                return currentRequest;
            }
            currentRequest = fetchSuggestions()
                .then((data) => {
                    currentData = { ...currentData, ...data };
                    renderPanel(currentData, { body });
                    return data;
                })
                .catch((error) => {
                    renderPanel(currentData, { body, notice: error.message || "Nao foi possivel atualizar as sugestoes." });
                    return null;
                })
                .finally(() => {
                    currentRequest = null;
                });
            return currentRequest;
        }

        async function handleAction(action) {
            if (action === "refresh") {
                await refresh();
                return;
            }
            if (action === "copy-summary") {
                await copyText(currentData.summary || currentData.suggested_notes || "");
                return;
            }
            if (action === "copy-reply") {
                await copyText(currentData.suggested_reply || "");
                return;
            }
            if (action === "fill-reply") {
                if (composer && currentData.suggested_reply) {
                    composer.value = composer.value ? `${composer.value}\n${currentData.suggested_reply}` : currentData.suggested_reply;
                    composer.focus();
                }
                return;
            }
            if (action === "apply-profile") {
                const payload = makeProfilePayload(currentData, { source: config.source });
                const response = await fetch(window.location.pathname + window.location.search, {
                    method: "POST",
                    headers: {
                        "X-Requested-With": "XMLHttpRequest",
                        "Accept": "application/json, text/plain, */*",
                    },
                    body: payload,
                });
                const data = await response.json().catch(() => null);
                if (!response.ok || !data || data.ok === false) {
                    throw new Error((data && data.error) || "Nao foi possivel aplicar os dados.");
                }
                window.location.reload();
            }
        }

        trigger.addEventListener("click", open);
        close?.addEventListener("click", closePanel);
        modal.addEventListener("click", (event) => {
            if (event.target === modal) {
                closePanel();
            }
        });
        document.addEventListener("keydown", (event) => {
            if (event.key === "Escape") {
                closePanel();
            }
        });
        body.addEventListener("click", async (event) => {
            const button = event.target instanceof Element ? event.target.closest("[data-ai-action]") : null;
            if (!button) return;
            event.preventDefault();
            const action = button.getAttribute("data-ai-action") || "";
            try {
                button.setAttribute("disabled", "disabled");
                await handleAction(action);
            } catch (error) {
                alert(error?.message || "Nao foi possivel concluir a acao.");
            } finally {
                button.removeAttribute("disabled");
            }
        });
    }

    initPanel({
        triggerId: "openWorkspaceAiButton",
        modalId: "workspaceAiOverlay",
        bodyId: "workspaceAiOverlayBody",
        closeId: "closeWorkspaceAiOverlay",
        initialId: "workspaceAiInitialData",
        composerSelector: "#reply-message",
        source: "workspace",
    });

    initPanel({
        triggerId: "m2AiButton",
        modalId: "m2AiOverlay",
        bodyId: "m2AiOverlayBody",
        closeId: "closeM2AiOverlay",
        initialId: "m2AiInitialData",
        composerSelector: "#m2Message",
        source: "mobile",
    });
})();
