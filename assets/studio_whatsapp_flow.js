(function () {
    'use strict';

    const canvas = document.querySelector('[data-flow-canvas]');
    const inspector = document.querySelector('[data-flow-inspector]');
    if (!canvas) {
        return;
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

    const observer = new MutationObserver(function () {
        window.requestAnimationFrame(decorateNodes);
    });
    observer.observe(canvas, { childList: true, subtree: true });
    decorateNodes();
}());
