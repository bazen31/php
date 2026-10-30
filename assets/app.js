(() => {
    const modal = document.getElementById('link-modal');
    const form = document.getElementById('link-form');
    const title = document.getElementById('modal-title');
    const fields = {
        id: document.getElementById('link-id'),
        title: document.getElementById('link-title'),
        url: document.getElementById('link-url'),
        description: document.getElementById('link-description'),
        collection: document.getElementById('link-collection'),
        tags: document.getElementById('link-tags'),
        favorite: document.getElementById('link-favorite')
    };

    const closeModal = () => {
        modal.classList.remove('open');
        modal.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = '';
    };

    const openModal = (link = null) => {
        form.reset();
        fields.id.value = link?.id || '';
        fields.title.value = link?.title || '';
        fields.url.value = link?.url || '';
        fields.description.value = link?.description || '';
        fields.collection.value = link?.collection || '';
        fields.tags.value = link?.tags || '';
        fields.favorite.checked = link?.favorite === '1';
        title.textContent = link ? 'Изменить ссылку' : 'Сохранить ссылку';
        modal.classList.add('open');
        modal.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';
        window.setTimeout(() => fields.title.focus(), 30);
    };

    document.querySelectorAll('[data-open-modal]').forEach((button) => {
        button.addEventListener('click', () => openModal());
    });
    document.querySelectorAll('[data-close-modal]').forEach((button) => {
        button.addEventListener('click', closeModal);
    });
    document.querySelectorAll('.edit-link').forEach((button) => {
        button.addEventListener('click', () => openModal({
            id: button.dataset.id,
            title: button.dataset.title,
            url: button.dataset.url,
            description: button.dataset.description,
            collection: button.dataset.collection,
            tags: button.dataset.tags,
            favorite: button.dataset.favorite
        }));
    });
    modal.addEventListener('click', (event) => {
        if (event.target === modal) closeModal();
    });
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && modal.classList.contains('open')) closeModal();
        if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'k') {
            event.preventDefault();
            openModal();
        }
    });
    document.querySelectorAll('[data-confirm-delete]').forEach((form) => {
        form.addEventListener('submit', (event) => {
            if (!window.confirm('Удалить эту ссылку из библиотеки?')) event.preventDefault();
        });
    });
    const linkGrid = document.querySelector('.link-grid');
    if (linkGrid) {
        document.querySelectorAll('[data-layout]').forEach((button) => {
            button.addEventListener('click', () => {
                linkGrid.classList.toggle('list-view', button.dataset.layout === 'list');
                document.querySelectorAll('[data-layout]').forEach((item) => {
                    item.classList.toggle('selected', item === button);
                });
            });
        });
    }

    const toast = document.querySelector('.toast');
    if (toast) window.setTimeout(() => toast.remove(), 3200);
})();
