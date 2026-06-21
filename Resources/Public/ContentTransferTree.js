;(() => {
    'use strict';

    const BASE_URL = '/neos/contenttransfer/tree-children';

    const ICON_EXPANDED = '▼';
    const ICON_COLLAPSED = '▶';
    const ICON_NO_CHILDREN = '';

    /**
     * @typedef {{ nodeAggregateId: string, label: string, nodeType: string, hasChildren: boolean }} TreeNode
     */

    /**
     * @typedef {{ workspaceName: string, title: string }} WorkspaceOption
     */

    /**
     * @param {HTMLElement} container
     */
    const initTree = (container) => {
        /** @type {string} */
        const contentRepository = container.dataset.contentRepository ?? '';
        /** @type {string} */
        const inputName = container.dataset.inputName ?? '';
        /** @type {string} */
        let workspaceName = container.dataset.workspaceName || 'live';
        /** @type {string} */
        const workspacesRaw = container.dataset.workspaces ?? '[]';
        /** @type {string} */
        const dimensionValues = container.dataset.dimensionValues || '{}';
        /** @type {string} */
        const label = container.dataset.label ?? '';

        /** @type {HTMLInputElement | null} */
        const hiddenInput = container.querySelector(
            `[data-tree-input="${inputName}"]`
        );

        /** @type {HTMLInputElement | null} */
        let hiddenWs = container.querySelector(
            `[data-tree-input="${inputName.replace('NodePath', 'Workspace').replace('ParentNodePath', 'Workspace')}"]`
        );
        if (!hiddenWs) {
            hiddenWs = container.querySelector('[data-tree-input="targetWorkspace"]')
                ?? container.querySelector('[data-tree-input="sourceWorkspace"]');
        }

        /** @type {HTMLDivElement | null} */
        const treeWrapper = container.querySelector('.ct-tree-wrapper');
        /** @type {HTMLDivElement | null} */
        const selectionInfo = container.querySelector('.ct-selection-info');
        /** @type {string | null} */
        let selectedNodeId = null;

        /** @type {WorkspaceOption[]} */
        let workspaces = [];
        try {
            workspaces = JSON.parse(workspacesRaw) || [];
        } catch (e) {
            workspaces = [];
        }

        /**
         * @param {string | null} parentNodeId
         * @returns {string}
         */
        const buildUrl = (parentNodeId) => {
            const params = new URLSearchParams();
            params.set('contentRepositoryId', contentRepository);
            params.set('workspaceName', workspaceName);
            if (parentNodeId) {
                params.set('parentNodeId', parentNodeId);
            }
            params.set('dimensionValues', dimensionValues);
            return `${BASE_URL}?${params.toString()}`;
        };

        const clearTree = () => {
            treeWrapper.innerHTML = '';
            selectedNodeId = null;
            if (selectionInfo) selectionInfo.textContent = '';
            if (hiddenInput) hiddenInput.value = '';
        };

        /**
         * @param {string | null} parentNodeId
         * @param {HTMLLIElement | null} parentLi
         */
        const loadChildren = (parentNodeId, parentLi) => {
            /** @type {HTMLUListElement | null} */
            let childrenUl = parentLi ? parentLi.querySelector('.ct-tree-children') : null;
            if (childrenUl?.getAttribute('data-loaded') === 'true') {
                const toggle = parentLi?.querySelector('.ct-tree-toggle');
                if (childrenUl.style.display === 'none') {
                    childrenUl.style.display = '';
                    if (toggle) toggle.textContent = ICON_EXPANDED;
                } else {
                    childrenUl.style.display = 'none';
                    if (toggle) toggle.textContent = ICON_COLLAPSED;
                }
                return;
            }

            const url = buildUrl(parentNodeId);
            if (parentLi) {
                const toggle = parentLi.querySelector('.ct-tree-toggle');
                if (toggle) toggle.textContent = '⏳';
            } else if (treeWrapper) {
                treeWrapper.innerHTML = '<div class="ct-loading">Loading...</div>';
            }

            fetch(url)
                .then((response) => response.json())
                .then((data) => {
                    /** @type {TreeNode[]} */
                    const children = data.children || [];
                    if (!childrenUl && parentLi) {
                        childrenUl = document.createElement('ul');
                        childrenUl.className = 'ct-tree-children';
                        parentLi.appendChild(childrenUl);
                    }
                    if (childrenUl) {
                        childrenUl.innerHTML = '';
                        children.forEach((child) => {
                            childrenUl.appendChild(createNodeElement(child));
                        });
                        childrenUl.setAttribute('data-loaded', 'true');
                        childrenUl.style.display = '';
                    } else if (!parentLi && treeWrapper) {
                        treeWrapper.innerHTML = '';
                        const rootUl = document.createElement('ul');
                        rootUl.className = 'ct-tree';
                        children.forEach((child) => {
                            rootUl.appendChild(createNodeElement(child));
                        });
                        treeWrapper.appendChild(rootUl);
                    }
                    if (parentLi) {
                        const toggle = parentLi.querySelector('.ct-tree-toggle');
                        if (toggle)
                            toggle.textContent = children.length > 0 ? ICON_EXPANDED : ICON_NO_CHILDREN;
                    }
                })
                .catch(() => {
                    if (parentLi) {
                        const toggle = parentLi.querySelector('.ct-tree-toggle');
                        toggle.textContent = ICON_COLLAPSED;
                    } else if (treeWrapper) {
                        treeWrapper.innerHTML = '<div class="ct-loading">Failed to load tree</div>';
                    }
                });
        };

        /**
         * @param {TreeNode} nodeData
         * @returns {HTMLLIElement}
         */
        const createNodeElement = (nodeData) => {
            const li = document.createElement('li');
            li.className = 'ct-tree-node';

            const toggle = document.createElement('span');
            toggle.className = 'ct-tree-toggle';
            if (nodeData.hasChildren) {
                toggle.textContent = ICON_COLLAPSED;
                toggle.addEventListener('click', (e) => {
                    e.stopPropagation();
                    loadChildren(nodeData.nodeAggregateId, li);
                });
            }
            li.appendChild(toggle);

            const labelEl = document.createElement('span');
            labelEl.className = 'ct-tree-label';
            labelEl.textContent = nodeData.label;
            labelEl.setAttribute('data-node-id', nodeData.nodeAggregateId);
            labelEl.addEventListener('click', () => {
                selectNode(nodeData.nodeAggregateId, nodeData.label);
            });
            li.appendChild(labelEl);

            if (nodeData.hasChildren) {
                const childrenUl = document.createElement('ul');
                childrenUl.className = 'ct-tree-children';
                childrenUl.style.display = 'none';
                childrenUl.setAttribute('data-loaded', 'false');
                li.appendChild(childrenUl);
            }

            return li;
        };

        /**
         * @param {string} nodeId
         * @param {string} nodeLabel
         */
        const selectNode = (nodeId, nodeLabel) => {
            selectedNodeId = nodeId;
            container.querySelectorAll('.ct-tree-label.ct-tree-label--selected').forEach((el) => {
                el.classList.remove('ct-tree-label--selected');
            });
            const selectedLabel = container.querySelector(`.ct-tree-label[data-node-id="${nodeId}"]`);
            if (selectedLabel) {
                selectedLabel.classList.add('ct-tree-label--selected');
                const li = selectedLabel.closest('.ct-tree-node');
                if (li) {
                    container.querySelectorAll('.ct-tree-node.ct-tree-node--selected').forEach((el) => {
                        el.classList.remove('ct-tree-node--selected');
                    });
                    li.classList.add('ct-tree-node--selected');
                }
            }
            if (hiddenInput) hiddenInput.value = nodeId;
            if (selectionInfo) {
                selectionInfo.textContent = `${label}: ${nodeLabel}`;
            }
        };

        const buildWorkspaceSelector = () => {
            if (workspaces.length <= 1) return;
            const header = container.querySelector('.ct-tree-header');
            if (!header) return;

            const select = document.createElement('select');
            select.className = 'ct-workspace-selector neos-span12';
            workspaces.forEach((ws) => {
                const opt = document.createElement('option');
                opt.value = ws.workspaceName;
                opt.textContent = ws.title;
                if (ws.workspaceName === workspaceName) {
                    opt.selected = true;
                }
                select.appendChild(opt);
            });
            select.addEventListener('change', () => {
                workspaceName = select.value;
                if (hiddenInput) hiddenInput.value = '';
                if (hiddenWs) hiddenWs.value = workspaceName;
                if (selectionInfo) selectionInfo.textContent = '';
                clearTree();
                loadChildren(null, null);
            });
            header.appendChild(select);
        };

        clearTree();
        loadChildren(null, null);
        buildWorkspaceSelector();
        if (hiddenWs) hiddenWs.value = workspaceName;
    };

    const init = () => {
        document.querySelectorAll('.content-transfer-tree').forEach((container) => {
            initTree(container);
        });
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
