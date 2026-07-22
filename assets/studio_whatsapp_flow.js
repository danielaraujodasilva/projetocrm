(function () {
    'use strict';

    const canvas = document.querySelector('[data-flow-canvas]');
    const inspector = document.querySelector('[data-flow-inspector]');
    const definitionInput = document.querySelector('#flowDefinitionInput');
    if (!canvas) {
        return;
    }

    const normalize = function (value) {
        return String(value || '')
            .toLowerCase()
            .normalize('NFD')
            .replace(/[\u0300-\u036f]/g, '')
            .replace(/[^a-z0-9]+/g, ' ')
            .trim();
    };

    function readSteps() {
        let definition = {};
        try {
            definition = JSON.parse(definitionInput ? definitionInput.value || '{}' : '{}');
        } catch (error) {
            definition = {};
        }
        return (Array.isArray(definition.steps) ? definition.steps : []).map(function (step) {
            let branchMap = {};
            if (typeof step.branch_map_json === 'string') {
                try {
                    branchMap = JSON.parse(step.branch_map_json || '{}');
                } catch (error) {
                    branchMap = {};
                }
            }
            if (!step.branch_map_json && step.branch_map && typeof step.branch_map === 'object' && !Array.isArray(step.branch_map)) {
                branchMap = step.branch_map;
            }
            return Object.assign({}, step, { branch_map: branchMap && typeof branchMap === 'object' ? branchMap : {} });
        });
    }

    function makeTargetMap(steps) {
        const byKey = {};
        steps.forEach(function (step, index) {
            if (step.step_key) {
                byKey[String(step.step_key)] = index;
            }
        });
        return byKey;
    }

    function stepEdges(step, index, steps, byKey) {
        const edges = [];
        const branchMap = step.branch_map && typeof step.branch_map === 'object' ? step.branch_map : {};
        Object.keys(branchMap).forEach(function (label) {
            const targetKey = String(branchMap[label] || '');
            if (targetKey && byKey[targetKey] !== undefined && byKey[targetKey] !== index) {
                edges.push({ target: byKey[targetKey], label: label });
            }
        });
        const defaultTarget = String(step.next_step_key || '');
        if (defaultTarget && byKey[defaultTarget] !== undefined && byKey[defaultTarget] !== index) {
            edges.push({ target: byKey[defaultTarget], label: 'Padrão' });
        } else if (!edges.length && steps[index + 1]) {
            edges.push({ target: index + 1, label: '' });
        }
        const seen = {};
        return edges.filter(function (edge) {
            const key = edge.target + '|' + normalize(edge.label);
            if (seen[key]) {
                return false;
            }
            seen[key] = true;
            return true;
        });
    }

    function decorateNodes() {
        canvas.querySelectorAll('.flow-node-wrap').forEach(function (wrap) {
            const editButton = wrap.querySelector('[data-flow-edit-index]');
            if (!editButton) {
                return;
            }

            let actions = wrap.querySelector('.flow-node-actions');
            if (!actions) {
                actions = document.createElement('span');
                actions.className = 'flow-node-actions';
                wrap.append(actions);
            }
            if (editButton.parentElement !== actions) {
                actions.append(editButton);
            }
            editButton.classList.add('flow-node-action-edit');
            editButton.innerHTML = '<i class="fa-solid fa-pen-to-square" aria-hidden="true"></i><span class="sr-only">Editar bloco</span>';
            editButton.title = 'Editar bloco';
            editButton.setAttribute('aria-label', 'Editar bloco');

            if (actions.querySelector('[data-flow-delete-index]')) {
                return;
            }
            const deleteButton = document.createElement('button');
            deleteButton.type = 'button';
            deleteButton.className = 'flow-node-quick-delete';
            deleteButton.dataset.flowDeleteIndex = editButton.dataset.flowEditIndex || '';
            deleteButton.innerHTML = '<i class="fa-solid fa-trash-can" aria-hidden="true"></i><span class="sr-only">Excluir bloco</span>';
            deleteButton.title = 'Excluir bloco';
            deleteButton.setAttribute('aria-label', 'Excluir bloco');
            deleteButton.addEventListener('click', function (event) {
                event.preventDefault();
                event.stopPropagation();
                const node = wrap.querySelector('.flow-node');
                if (node) {
                    node.click();
                }
                window.setTimeout(function () {
                    const modalDelete = inspector && inspector.querySelector('[data-flow-delete]');
                    if (modalDelete) {
                        modalDelete.click();
                    }
                }, 35);
            });
            actions.append(deleteButton);
        });
    }

    function setHiddenBranchMap(map) {
        const field = document.querySelector('[data-flow-field="branch_map_json"]');
        if (!field) {
            return;
        }
        field.value = JSON.stringify(map || {});
        field.dispatchEvent(new Event('input', { bubbles: true }));
    }

    function currentStepIndex() {
        const selected = canvas.querySelector('.flow-node.is-selected');
        return selected ? Number(selected.dataset.index || 0) : 0;
    }

    function populateConnections() {
        const form = inspector && inspector.querySelector('[data-flow-form]');
        if (!form || form.hidden) {
            return;
        }
        const steps = readSteps();
        const selectedIndex = currentStepIndex();
        const step = steps[selectedIndex];
        if (!step) {
            return;
        }
        const nextSelect = form.querySelector('[data-flow-next-step]');
        if (nextSelect) {
            const current = String(step.next_step_key || '');
            nextSelect.replaceChildren();
            const fallback = document.createElement('option');
            fallback.value = '';
            fallback.textContent = 'Seguir a ordem abaixo';
            nextSelect.append(fallback);
            steps.forEach(function (candidate, index) {
                if (index === selectedIndex) {
                    return;
                }
                const option = document.createElement('option');
                option.value = String(candidate.step_key || '');
                option.textContent = String(index + 1).padStart(2, '0') + ' · ' + (candidate.title || 'Bloco sem título');
                nextSelect.append(option);
            });
            nextSelect.value = current;
            if (!nextSelect.dataset.flowConnectionBound) {
                nextSelect.dataset.flowConnectionBound = '1';
                nextSelect.addEventListener('change', function () {
                    nextSelect.dispatchEvent(new Event('input', { bubbles: true }));
                });
            }
        }
        const branchList = form.querySelector('[data-flow-branches]');
        if (!branchList) {
            return;
        }
        const options = Array.isArray(step.options) ? step.options.filter(Boolean) : [];
        const branchMap = step.branch_map && typeof step.branch_map === 'object' ? step.branch_map : {};
        branchList.replaceChildren();
        if (!options.length) {
            const empty = document.createElement('div');
            empty.className = 'flow-branch-empty';
            empty.textContent = 'Adicione opções neste bloco para criar caminhos específicos.';
            branchList.append(empty);
            return;
        }
        const help = document.createElement('small');
        help.className = 'flow-branch-help';
        help.textContent = 'Exemplo: “Sim” pode continuar para confirmação e “Não” pode voltar para escolher outro horário.';
        branchList.append(help);
        options.forEach(function (label) {
            const row = document.createElement('label');
            row.className = 'flow-branch-row';
            const title = document.createElement('span');
            title.textContent = label;
            const select = document.createElement('select');
            select.dataset.flowBranchLabel = label;
            const empty = document.createElement('option');
            empty.value = '';
            empty.textContent = 'Seguir a ordem abaixo';
            select.append(empty);
            steps.forEach(function (candidate, index) {
                if (index === selectedIndex) {
                    return;
                }
                const option = document.createElement('option');
                option.value = String(candidate.step_key || '');
                option.textContent = String(index + 1).padStart(2, '0') + ' · ' + (candidate.title || 'Bloco sem título');
                select.append(option);
            });
            const target = Object.keys(branchMap).find(function (key) {
                return normalize(key) === normalize(label);
            });
            select.value = target ? String(branchMap[target] || '') : '';
            select.addEventListener('change', function () {
                const updated = Object.assign({}, branchMap);
                Object.keys(updated).forEach(function (key) {
                    if (normalize(key) === normalize(label)) {
                        delete updated[key];
                    }
                });
                if (select.value) {
                    updated[label] = select.value;
                }
                setHiddenBranchMap(updated);
            });
            row.append(title, select);
            branchList.append(row);
        });
    }

    function drawGraph() {
        decorateNodes();
        const wrappers = Array.from(canvas.querySelectorAll('.flow-node-wrap'));
        const steps = readSteps();
        if (!wrappers.length || !steps.length) {
            return;
        }
        const byKey = makeTargetMap(steps);
        const adjacency = steps.map(function (step, index) {
            return stepEdges(step, index, steps, byKey);
        });
        const levels = steps.map(function () { return null; });
        levels[0] = 0;
        const queue = [0];
        while (queue.length) {
            const index = queue.shift();
            adjacency[index].forEach(function (edge) {
                const level = levels[index] + 1;
                if (levels[edge.target] === null || levels[edge.target] > level) {
                    levels[edge.target] = level;
                    queue.push(edge.target);
                }
            });
        }
        let lastLevel = 0;
        levels.forEach(function (level, index) {
            if (level === null) {
                levels[index] = ++lastLevel;
            }
            lastLevel = Math.max(lastLevel, levels[index]);
        });
        const groups = {};
        levels.forEach(function (level, index) {
            groups[level] = groups[level] || [];
            groups[level].push(index);
        });
        canvas.classList.add('is-flow-graph');
        canvas.style.position = 'relative';
        canvas.style.minHeight = ((lastLevel + 1) * 184 + 48) + 'px';
        wrappers.forEach(function (wrap, index) {
            const group = groups[levels[index]] || [index];
            const column = group.indexOf(index);
            const columns = Math.min(group.length, 3);
            const row = Math.floor(column / columns);
            const col = column % columns;
            const width = 100 / columns;
            wrap.style.position = 'absolute';
            wrap.style.top = (levels[index] * 184 + row * 150) + 'px';
            wrap.style.left = 'calc(' + (col * width) + '% + 8px)';
            wrap.style.width = 'calc(' + width + '% - 16px)';
            wrap.style.zIndex = '2';
            const connector = wrap.querySelector('.flow-connector');
            if (connector) {
                connector.hidden = true;
            }
        });
        let svg = canvas.querySelector('.flow-graph-edges');
        if (!svg) {
            svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
            svg.classList.add('flow-graph-edges');
            canvas.prepend(svg);
        }
        const canvasRect = canvas.getBoundingClientRect();
        svg.replaceChildren();
        svg.setAttribute('width', String(Math.max(1, canvas.clientWidth)));
        svg.setAttribute('height', String(Math.max(1, canvas.scrollHeight)));
        svg.setAttribute('viewBox', '0 0 ' + Math.max(1, canvas.clientWidth) + ' ' + Math.max(1, canvas.scrollHeight));
        const defs = document.createElementNS('http://www.w3.org/2000/svg', 'defs');
        const marker = document.createElementNS('http://www.w3.org/2000/svg', 'marker');
        marker.id = 'flow-arrow';
        marker.setAttribute('markerWidth', '8');
        marker.setAttribute('markerHeight', '8');
        marker.setAttribute('refX', '6');
        marker.setAttribute('refY', '3');
        marker.setAttribute('orient', 'auto');
        const arrow = document.createElementNS('http://www.w3.org/2000/svg', 'path');
        arrow.setAttribute('d', 'M0,0 L0,6 L6,3 z');
        arrow.setAttribute('fill', '#7ba89a');
        marker.append(arrow);
        defs.append(marker);
        svg.append(defs);
        adjacency.forEach(function (edges, index) {
            const source = wrappers[index].querySelector('.flow-node');
            if (!source) {
                return;
            }
            const sourceRect = source.getBoundingClientRect();
            edges.forEach(function (edge) {
                const target = wrappers[edge.target] && wrappers[edge.target].querySelector('.flow-node');
                if (!target) {
                    return;
                }
                const targetRect = target.getBoundingClientRect();
                const x1 = sourceRect.left + sourceRect.width / 2 - canvasRect.left;
                const y1 = sourceRect.bottom - canvasRect.top;
                const x2 = targetRect.left + targetRect.width / 2 - canvasRect.left;
                const y2 = targetRect.top - canvasRect.top;
                const curve = Math.max(24, (y2 - y1) * 0.45);
                const path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
                path.setAttribute('d', 'M ' + x1 + ' ' + y1 + ' C ' + x1 + ' ' + (y1 + curve) + ', ' + x2 + ' ' + (y2 - curve) + ', ' + x2 + ' ' + y2);
                path.setAttribute('class', edge.label ? 'is-branch-edge' : 'is-default-edge');
                path.setAttribute('marker-end', 'url(#flow-arrow)');
                svg.append(path);
                if (edge.label) {
                    const text = document.createElementNS('http://www.w3.org/2000/svg', 'text');
                    text.setAttribute('x', String((x1 + x2) / 2 + 6));
                    text.setAttribute('y', String((y1 + y2) / 2));
                    text.setAttribute('class', 'flow-edge-label');
                    text.textContent = edge.label;
                    svg.append(text);
                }
            });
        });
        populateConnections();
    }

    let frame = null;
    const scheduleDraw = function () {
        if (frame) {
            cancelAnimationFrame(frame);
        }
        frame = requestAnimationFrame(function () {
            frame = null;
            drawGraph();
        });
    };
    const observer = new MutationObserver(function (records) {
        if (records.length && records.every(function (record) {
            return record.target && record.target.classList && record.target.classList.contains('flow-graph-edges');
        })) {
            return;
        }
        scheduleDraw();
    });
    observer.observe(canvas, { childList: true, subtree: true });
    scheduleDraw();
    window.addEventListener('resize', scheduleDraw);
}());
