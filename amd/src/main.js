// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Site-wide floating chat bubble and modal for local_zoomchat.
 *
 * @module    local_zoomchat/main
 * @copyright 2026 Jonathan Champ
 * @license   https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {call as fetchMany} from 'core/ajax';
import Modal from 'core/modal';
import ModalEvents from 'core/modal_events';
import Notification from 'core/notification';
import {getStrings} from 'core/str';
import Templates from 'core/templates';
import * as PubSub from 'core/pubsub';
import * as RealTimeEvents from 'tool_realtime/events';

let config = null;
let strSelect = '';
let strSend = '';
let strSending = '';
let strError = '';
let strErrorLoad = '';
let strErrorSend = '';
let strUnknown = '';
let strImage = '';
let strFile = '';
let strNew = '';
let strUnreadMessages = '';
let strYou = '';
let strZoomchat = '';

let bubble = null;
let unreadCount = 0;
let toastContainer = null;
let modalInstance = null;
let convListEl = null;
let chatEl = null;
let messagesList = null;
let messageInput = null;
let sendBtn = null;
let attachBtn = null;
let fileInput = null;
let convHeader = null;
let backBtn = null;
let previewContainer = null;
let selectedPartnerId = null;
let pendingAttachments = [];
let realtimeConnected = true;
let pollTimer = null;

const uploadUrl = M.cfg.wwwroot + '/local/zoomchat/ajax.php';

const getTimeStr = (ts) => {
    const time = new Date(ts * 1000);
    return time.getFullYear() + '-' +
        (time.getMonth() + 1).toString().padStart(2, '0') + '-' +
        time.getDate().toString().padStart(2, '0') + ' ' +
        time.getHours().toString().padStart(2, '0') + ':' +
        time.getMinutes().toString().padStart(2, '0');
};

const formatRelativeTime = (ts) => {
    const diff = Math.floor((Date.now() - ts * 1000) / 1000);
    if (diff < 60) {
        return 'now';
    }
    if (diff < 3600) {
        return Math.floor(diff / 60) + 'm';
    }
    if (diff < 86400) {
        return Math.floor(diff / 3600) + 'h';
    }
    if (diff < 604800) {
        return Math.floor(diff / 86400) + 'd';
    }
    return getTimeStr(ts);
};

const stripHtml = (html) => {
    const div = document.createElement('div');
    div.innerHTML = html;
    return div.textContent || div.innerText || '';
};

// --- Bubble ---

const createBubble = () => {
    bubble = document.createElement('button');
    bubble.id = 'local-zoomchat-bubble';
    bubble.setAttribute('aria-label', strZoomchat);
    bubble.setAttribute('aria-haspopup', 'dialog');
    bubble.innerHTML = '<span class="local-zoomchat-bubble-icon" aria-hidden="true">💬</span>';
    bubble.addEventListener('click', () => openModal());
    document.body.appendChild(bubble);
};

const updateBubbleBadge = () => {
    unreadCount++;
    let badge = bubble.querySelector('.local-zoomchat-bubble-count');
    if (!badge) {
        badge = document.createElement('span');
        badge.className = 'local-zoomchat-bubble-count';
        badge.setAttribute('aria-hidden', 'true');
        bubble.appendChild(badge);
    }
    badge.textContent = unreadCount;
    bubble.setAttribute(
        'aria-label',
        strZoomchat + ', ' + strUnreadMessages.replace('{$a}', String(unreadCount))
    );
};

// --- Toasts ---

const createToastContainer = () => {
    toastContainer = document.createElement('div');
    toastContainer.className = 'local-zoomchat-toast-container';
    toastContainer.setAttribute('aria-live', 'polite');
    toastContainer.setAttribute('aria-relevant', 'additions');
    document.body.appendChild(toastContainer);
};

const showToast = async(payload) => {
    const raw = payload.message || '';
    const stripped = stripHtml(raw).trim();
    let preview = stripped.substring(0, 120);
    if (!preview) {
        if (raw.includes('<img')) {
            preview = strImage;
        } else if (raw.includes('<a ')) {
            preview = strFile;
        } else {
            preview = strNew;
        }
    }

    const html = await Templates.render('local_zoomchat/toast', {
        fromuserid: payload.from_userid,
        sendername: payload.sender_name || strUnknown,
        preview: preview,
        timestamp: formatRelativeTime(payload.timestamp || Math.floor(Date.now() / 1000)),
    });
    const wrapper = document.createElement('div');
    wrapper.innerHTML = html;
    const toast = wrapper.firstElementChild;

    toast.addEventListener('click', (e) => {
        if (e.target.closest('.btn-close')) {
            toast.remove();
            return;
        }
        toast.remove();
        openModal(payload.from_userid);
    });

    const toastBody = toast.querySelector('.toast-body');
    toastBody.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' || e.key === ' ') {
            e.preventDefault();
            toast.remove();
            openModal(payload.from_userid);
        }
    });

    toastContainer.appendChild(toast);

    setTimeout(() => {
        if (toast.parentNode) {
            if (toast.contains(document.activeElement)) {
                bubble.focus();
            }
            if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
                toast.remove();
            } else {
                toast.style.opacity = '0';
                setTimeout(() => toast.remove(), 300);
            }
        }
    }, 6000);
};

// --- Modal ---

const createModal = async() => {
    const bodyHtml = await Templates.render('local_zoomchat/modal_body', {});
    modalInstance = await Modal.create({
        title: strZoomchat,
        body: bodyHtml,
        large: true,
        show: false,
        removeOnClose: false,
    });
    modalInstance.hideFooter();
    modalInstance.getModal().addClass('modal-xl');

    const root = modalInstance.getRoot()[0];
    root.classList.add('local-zoomchat-modal-root');

    convListEl = root.querySelector('.local-zoomchat-convlist-items');
    chatEl = root.querySelector('#local-zoomchat-chat');
    messagesList = root.querySelector('#local-zoomchat-messages-list');
    messageInput = root.querySelector('#local-zoomchat-message-input');
    sendBtn = root.querySelector('#local-zoomchat-send-btn');
    attachBtn = root.querySelector('#local-zoomchat-attach-btn');
    fileInput = root.querySelector('#local-zoomchat-file-input');
    convHeader = root.querySelector('.local-zoomchat-chat-partner-name');
    backBtn = root.querySelector('.local-zoomchat-back-btn');
    previewContainer = root.querySelector('#local-zoomchat-attachment-previews');

    const modalRoot = modalInstance.getRoot();
    modalRoot.on(ModalEvents.shown, () => {
        loadConversations();
    });

    modalRoot.on(ModalEvents.hidden, () => {
        selectedPartnerId = null;
        backBtn.style.display = 'none';
        bubble.focus();
        if (pollTimer) {
            clearInterval(pollTimer);
            pollTimer = null;
        }
    });

    backBtn.addEventListener('click', () => {
        showConversationList();
    });

    sendBtn.addEventListener('click', () => sendMessage());
    messageInput.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            sendMessage();
        }
    });

    attachBtn.addEventListener('click', () => fileInput.click());
    fileInput.addEventListener('change', (e) => {
        const file = e.target.files[0];
        if (file) {
            uploadImage(file);
        }
        fileInput.value = '';
    });

    messageInput.addEventListener('paste', (e) => {
        const items = e.clipboardData.items;
        for (const item of items) {
            if (item.type.startsWith('image/')) {
                e.preventDefault();
                const file = item.getAsFile();
                if (file) {
                    uploadImage(file);
                }
            }
        }
    });
};

const openModal = (partnerId) => {
    selectedPartnerId = partnerId || null;
    modalInstance.show();
};

const showConversationList = () => {
    const activeItem = convListEl.querySelector('.local-zoomchat-conv-item.active');
    convListEl.closest('#local-zoomchat-convlist').classList.remove('local-zoomchat-convlist-hidden');
    backBtn.style.display = 'none';
    selectedPartnerId = null;
    messagesList.innerHTML = '';
    convHeader.textContent = strSelect;
    messageInput.disabled = true;
    sendBtn.disabled = true;
    attachBtn.disabled = true;
    if (pollTimer) {
        clearInterval(pollTimer);
        pollTimer = null;
    }
    if (activeItem) {
        activeItem.focus();
    }
};

// --- Conversations ---

const createConvItem = async(user) => {
    const preview = stripHtml(user.lastmessage || '').substring(0, 60);
    const html = await Templates.render('local_zoomchat/conv_item', {
        id: user.id,
        name: user.name,
        picture: user.picture || '',
        preview: preview,
        lastmessagetime: user.lastmessagetime ? formatRelativeTime(user.lastmessagetime) : '',
        unreadcount: user.unreadcount || 0,
        hasunread: (user.unreadcount || 0) > 0,
    });
    const wrapper = document.createElement('div');
    wrapper.innerHTML = html;
    const item = wrapper.firstElementChild;
    item.addEventListener('click', () => selectConversation(user.id, user.name));
    item.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' || e.key === ' ') {
            e.preventDefault();
            selectConversation(user.id, user.name);
        }
    });

    return item;
};

const loadConversations = async() => {
    const loadingHtml = await Templates.render('local_zoomchat/conv_list_state', {loading: true});
    convListEl.innerHTML = loadingHtml;
    convListEl.setAttribute('aria-busy', 'true');

    try {
        const data = await fetchMany([{
            methodname: 'local_zoomchat_get_users',
            args: {courseid: config.courseid || 0}
        }])[0];

        convListEl.innerHTML = '';
        convListEl.setAttribute('aria-busy', 'false');

        if (!data.users || data.users.length === 0) {
            const emptyHtml = await Templates.render('local_zoomchat/conv_list_state', {
                loading: false,
                message: 'No conversations yet',
            });
            convListEl.innerHTML = emptyHtml;
            return;
        }

        const items = await Promise.all(data.users.map((user) => createConvItem(user)));
        items.forEach((item) => convListEl.appendChild(item));

        if (selectedPartnerId) {
            selectConversation(selectedPartnerId, '');
        } else if (data.users.length > 0) {
            selectConversation(data.users[0].id, data.users[0].name);
        }
    } catch (ex) {
        const errorHtml = await Templates.render('local_zoomchat/conv_list_state', {
            loading: false,
            message: strErrorLoad,
        });
        convListEl.innerHTML = errorHtml;
        convListEl.setAttribute('aria-busy', 'false');
    }
};

// --- Chat ---

const selectConversation = (partnerId, partnerName) => {
    selectedPartnerId = partnerId;

    convListEl.querySelectorAll('.local-zoomchat-conv-item').forEach((el) => {
        const active = parseInt(el.dataset.userid) === partnerId;
        el.classList.toggle('active', active);
        if (active) {
            el.setAttribute('aria-current', 'true');
        } else {
            el.removeAttribute('aria-current');
        }
    });

    convListEl.closest('#local-zoomchat-convlist').classList.add('local-zoomchat-convlist-hidden');
    backBtn.style.display = 'inline-block';

    messagesList.innerHTML = '';
    messageInput.disabled = false;
    sendBtn.disabled = false;
    attachBtn.disabled = false;
    messageInput.focus();

    if (partnerName) {
        convHeader.textContent = partnerName;
    }

    loadMessages(partnerId, 0);

    if (!realtimeConnected && !pollTimer) {
        pollTimer = setInterval(() => loadNewMessages(partnerId), 5000);
    }
};

const loadMessages = async(partnerId, since) => {
    try {
        const data = await fetchMany([{
            methodname: 'local_zoomchat_get_messages',
            args: {partnerid: partnerId, lasttime: since || 0}
        }])[0];

        if (data.messages) {
            await renderMessages(data.messages);
            scrollMessagesDown();
        }
    } catch (ex) {
        // Silently handle.
    }
};

const loadNewMessages = (partnerId) => {
    let lasttime = 0;
    const msgs = messagesList.querySelectorAll('.local-zoomchat-msg');
    if (msgs.length) {
        const last = msgs[msgs.length - 1];
        lasttime = parseInt(last.dataset.timestamp) || 0;
    }
    loadMessages(partnerId, lasttime);
};

const renderMessages = async(msgs) => {
    const html = await Templates.render('local_zoomchat/messages', {
        messages: msgs.map((msg) => ({
            id: msg.id,
            message: msg.message,
            timestamp: msg.timestamp,
            timestampformatted: getTimeStr(msg.timestamp),
            mymessage: msg.mymessage,
            sendername: msg.mymessage ? strYou : (msg.sendername || strUnknown),
        })),
    });
    messagesList.insertAdjacentHTML('beforeend', html);

    const empty = chatEl.querySelector('.local-zoomchat-empty');
    if (empty) {
        empty.remove();
    }
};

const scrollMessagesDown = () => {
    const container = chatEl.querySelector('.local-zoomchat-messages-container');
    container.scrollTop = container.scrollHeight;
};

const clearUnreadForPartner = (partnerId) => {
    const item = convListEl.querySelector('[data-userid="' + partnerId + '"]');
    if (item) {
        item.classList.remove('local-zoomchat-has-unread');
        const badge = item.querySelector('.local-zoomchat-conv-unread');
        if (badge) {
            badge.textContent = '';
        }
    }
};

// --- Send ---

const sendMessage = async() => {
    const text = messageInput.value;
    if (!selectedPartnerId || (text.trim() === '' && pendingAttachments.length === 0)) {
        return;
    }

    sendBtn.textContent = strSending;
    sendBtn.disabled = true;

    const filehtml = pendingAttachments.map((a) => a.html).join('');
    const fullmessage = text.trim() + filehtml;

    const sendArgs = {
        recipientid: selectedPartnerId,
        message: fullmessage,
    };
    if (pendingAttachments.length > 0) {
        sendArgs.fileitemids = pendingAttachments.map((a) => a.fileitemid).join(',');
    }

    try {
        const data = await fetchMany([{
            methodname: 'local_zoomchat_send_message',
            args: sendArgs
        }])[0];
        if (data.success) {
            messageInput.value = '';
            pendingAttachments = [];
            renderAttachmentPreviews();
            loadNewMessages(selectedPartnerId);
        } else {
            Notification.alert(strError, data.error || strErrorSend);
        }
    } catch (ex) {
        Notification.alert(strError, strErrorSend);
    }

    sendBtn.textContent = strSend;
    sendBtn.disabled = false;
    messageInput.focus();
};

// --- File upload ---

const uploadImage = async(file) => {
    const formData = new FormData();
    formData.append('action', 'upload_attachment');
    formData.append('userid', config.userid);
    formData.append('sesskey', M.cfg.sesskey);
    formData.append('attachment', file);

    try {
        const response = await fetch(uploadUrl, {
            method: 'POST',
            body: formData
        });
        const data = await response.json();
        if (data.html && data.fileitemid) {
            pendingAttachments.push({fileitemid: data.fileitemid, html: data.html});
            renderAttachmentPreviews();
        } else if (data.error) {
            Notification.alert(strError, data.error);
        }
    } catch (ex) {
        Notification.alert(strError, strErrorSend);
    }
};

const removeAttachment = (e) => {
    const btn = e.target.closest('.local-zoomchat-attach-remove');
    if (!btn) {
        return;
    }
    const idx = parseInt(btn.dataset.idx, 10);
    if (!isNaN(idx) && idx >= 0 && idx < pendingAttachments.length) {
        pendingAttachments.splice(idx, 1);
        renderAttachmentPreviews();
    }
};

const renderAttachmentPreviews = async() => {
    previewContainer.innerHTML = '';
    previewContainer.removeEventListener('click', removeAttachment);
    const atts = pendingAttachments.slice();
    for (let i = 0; i < atts.length; i++) {
        const html = await Templates.render('local_zoomchat/attachment_preview', {
            html: atts[i].html,
            idx: i,
        });
        const wrapper = document.createElement('div');
        wrapper.innerHTML = html;
        previewContainer.appendChild(wrapper.firstElementChild);
    }
    previewContainer.addEventListener('click', removeAttachment);
};

// --- Realtime ---

const handleRealtimeEvent = async(eventData) => {
    const {component, area, payload} = eventData;

    if (component !== 'local_zoomchat' || area !== 'zoomchat') {
        return;
    }

    if (payload.from_userid == config.userid) {
        return;
    }

    realtimeConnected = true;
    if (pollTimer) {
        clearInterval(pollTimer);
        pollTimer = null;
    }
    updateBubbleBadge();

    const isModalOpen = modalInstance &&
        modalInstance.getRoot()[0].classList.contains('show');

    if (!isModalOpen) {
        showToast(payload);
        return;
    }

    const existing = messagesList.querySelector(
        '[data-messageid="' + payload.messageid + '"]'
    );
    if (existing) {
        const bubble = existing.querySelector('.local-zoomchat-bubble');
        if (bubble) {
            bubble.innerHTML = payload.message;
        }
        scrollMessagesDown();
        clearUnreadForPartner(payload.from_userid);
        return;
    }

    if (selectedPartnerId && Number(payload.from_userid) === Number(selectedPartnerId)) {
        await renderMessages([{
            id: payload.messageid,
            message: payload.message,
            timestamp: payload.timestamp,
            mymessage: false,
            sendername: payload.sender_name || strUnknown,
        }]);
        scrollMessagesDown();
        clearUnreadForPartner(payload.from_userid);
    } else {
        showToast(payload);
        let item = convListEl.querySelector(
            '[data-userid="' + payload.from_userid + '"]'
        );
        if (!item) {
            const newItem = await createConvItem({
                id: payload.from_userid,
                name: payload.sender_name || '',
                picture: payload.sender_picture || '',
                unreadcount: 1,
            });
            convListEl.prepend(newItem);
        } else {
            const badge = item.querySelector('.local-zoomchat-conv-unread');
            if (badge) {
                const current = parseInt(badge.textContent, 10) || 0;
                badge.textContent = current + 1;
            }
            item.classList.add('local-zoomchat-has-unread');
        }
    }
};

const handleConnectionLost = () => {
    realtimeConnected = false;
    if (selectedPartnerId && !pollTimer) {
        pollTimer = setInterval(() => loadNewMessages(selectedPartnerId), 5000);
    }
};

// --- Init ---

export const init = async(cfg) => {
    if (window.__localZoomchatLoaded) {
        return;
    }
    config = cfg;

    window.__localZoomchatLoaded = true;

    try {
        [
            strZoomchat,
            strSelect,
            strSend,
            strSending,
            strError,
            strErrorLoad,
            strErrorSend,
            strUnknown,
            strImage,
            strFile,
            strNew,
            strUnreadMessages,
            strYou,
        ] = await getStrings([
            {key: 'pluginname', component: 'local_zoomchat'},
            {key: 'selectcontact', component: 'local_zoomchat'},
            {key: 'send', component: 'local_zoomchat'},
            {key: 'sending', component: 'local_zoomchat'},
            {key: 'error', component: 'core'},
            {key: 'error:loadfailed', component: 'local_zoomchat'},
            {key: 'error:sendfailed', component: 'local_zoomchat'},
            {key: 'unknownuser', component: 'local_zoomchat'},
            {key: 'image', component: 'local_zoomchat'},
            {key: 'file', component: 'local_zoomchat'},
            {key: 'newmessage', component: 'local_zoomchat'},
            {key: 'unreadmessages', component: 'local_zoomchat'},
            {key: 'you', component: 'local_zoomchat'},
        ]);
    } catch (error) {
        strZoomchat = 'Zoom Chat';
        strSelect = 'Select a contact';
        strSend = 'Send';
        strSending = 'Sending...';
        strError = 'Error';
        strErrorLoad = 'Failed to load messages.';
        strErrorSend = 'Failed to send message.';
        strUnknown = 'Unknown user';
        strImage = 'Image';
        strFile = 'File';
        strNew = 'New message';
        strUnreadMessages = '{$a} unread messages';
        strYou = 'You';
    }

    createBubble();
    createToastContainer();
    await createModal();

    PubSub.subscribe(RealTimeEvents.EVENT, handleRealtimeEvent);
    PubSub.subscribe(RealTimeEvents.CONNECTION_LOST, handleConnectionLost);
};

export default {
    init,
};
