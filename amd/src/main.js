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
import * as PubSub from 'core/pubsub';
import * as RealTimeEvents from 'tool_realtime/events';

let config = null;
let strNoconversations = '';
let strMessage = '';
let strSend = '';
let strEnter = '';
let strSelect = '';
let strAttach = '';
let strSending = '';
let strError = '';
let strErrorLoad = '';
let strErrorSend = '';
let strUnknown = '';
let strImage = '';
let strFile = '';
let strNew = '';
let strClose = '';
let strRemove = '';
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

const escapeHtml = (str) => {
    const el = document.createElement('span');
    el.textContent = str;
    return el.innerHTML;
};

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
    bubble.innerHTML = '<span class="local-zoomchat-bubble-icon">💬</span>';
    bubble.addEventListener('click', () => openModal());
    document.body.appendChild(bubble);
};

const updateBubbleBadge = () => {
    unreadCount++;
    let badge = bubble.querySelector('.local-zoomchat-bubble-count');
    if (!badge) {
        badge = document.createElement('span');
        badge.className = 'local-zoomchat-bubble-count';
        bubble.appendChild(badge);
    }
    badge.textContent = unreadCount;
};

// --- Toasts ---

const createToastContainer = () => {
    toastContainer = document.createElement('div');
    toastContainer.className = 'local-zoomchat-toast-container';
    toastContainer.setAttribute('aria-live', 'polite');
    toastContainer.setAttribute('aria-relevant', 'additions');
    document.body.appendChild(toastContainer);
};

const showToast = (payload) => {
    const sender = payload.sender_name || strUnknown;
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

    const toast = document.createElement('div');
    toast.className = 'local-zoomchat-toast';
    toast.setAttribute('role', 'alert');
    toast.innerHTML =
        '<div class="toast-header">' +
        '<strong class="me-auto">' + escapeHtml(sender) + '</strong>' +
        '<small class="text-muted ms-2">' +
        formatRelativeTime(payload.timestamp || Math.floor(Date.now() / 1000)) +
        '</small>' +
        '<button type="button" class="btn-close" data-dismiss="toast" aria-label="' + strClose + '"></button>' +
        '</div>' +
        '<div class="toast-body text-break">' + escapeHtml(preview) + '</div>';

    toast.addEventListener('click', (e) => {
        if (e.target.closest('.btn-close')) {
            toast.remove();
            return;
        }
        toast.remove();
        openModal(payload.from_userid);
    });

    toastContainer.appendChild(toast);

    setTimeout(() => {
        if (toast.parentNode) {
            toast.style.opacity = '0';
            setTimeout(() => toast.remove(), 300);
        }
    }, 6000);
};

// --- Modal body HTML ---

const buildModalBody = () => {
    return '<div id="local-zoomchat-modal-body">' +
        '<div id="local-zoomchat-convlist">' +
        '<div class="local-zoomchat-convlist-header">' + escapeHtml(strMessage) + '</div>' +
        '<div class="local-zoomchat-convlist-items"></div>' +
        '</div>' +
        '<div id="local-zoomchat-chat">' +
        '<div class="local-zoomchat-chat-header">' +
        '<button class="local-zoomchat-back-btn" aria-label="' + strClose + '">&larr;</button>' +
        '<span class="local-zoomchat-chat-partner-name">' + escapeHtml(strSelect) + '</span>' +
        '</div>' +
        '<div class="local-zoomchat-messages-container">' +
        '<div class="local-zoomchat-empty">' + escapeHtml(strSelect) + '</div>' +
        '<ul id="local-zoomchat-messages-list"></ul>' +
        '</div>' +
        '<div class="local-zoomchat-input-area">' +
        '<div class="local-zoomchat-input-wrapper">' +
        '<div id="local-zoomchat-attachment-previews"></div>' +
        '<textarea id="local-zoomchat-message-input" rows="1" placeholder="' +
        escapeHtml(strEnter) + '" disabled></textarea>' +
        '</div>' +
        '<div id="local-zoomchat-input-actions">' +
        '<input type="file" id="local-zoomchat-file-input" accept="image/*" hidden>' +
        '<button type="button" id="local-zoomchat-attach-btn" class="btn btn-outline-secondary btn-sm local-zoomchat-btn" ' +
        'title="' + escapeHtml(strAttach) + '" disabled>📎</button>' +
        '<button type="button" id="local-zoomchat-send-btn" class="btn btn-primary btn-sm local-zoomchat-btn" disabled>' +
        escapeHtml(strSend) + '</button>' +
        '</div>' +
        '</div>' +
        '</div>' +
        '</div>';
};

// --- Modal ---

const createModal = async() => {
    modalInstance = await Modal.create({
        title: strZoomchat,
        body: buildModalBody(),
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
};

// --- Conversations ---

const loadConversations = async() => {
    convListEl.innerHTML = '<div class="local-zoomchat-loading">' + escapeHtml(strSending) + '</div>';

    try {
        const data = await fetchMany([{
            methodname: 'local_zoomchat_get_users',
            args: {courseid: config.courseid || 0}
        }])[0];

        convListEl.innerHTML = '';

        if (!data.users || data.users.length === 0) {
            convListEl.innerHTML = '<div class="local-zoomchat-empty">' +
                escapeHtml(strNoconversations) + '</div>';
            return;
        }

        data.users.forEach((user) => {
            const item = document.createElement('div');
            item.className = 'local-zoomchat-conv-item' +
                (user.unreadcount > 0 ? ' local-zoomchat-has-unread' : '');
            item.dataset.userid = user.id;
            item.setAttribute('role', 'button');
            item.setAttribute('tabindex', '0');
            item.setAttribute('aria-label', user.name);

            const preview = stripHtml(user.lastmessage).substring(0, 60);

            item.innerHTML =
                '<div class="local-zoomchat-conv-avatar">' + (user.picture || '') + '</div>' +
                '<div class="local-zoomchat-conv-info">' +
                '<span class="local-zoomchat-conv-name">' + escapeHtml(user.name) + '</span>' +
                '<span class="local-zoomchat-conv-preview">' + escapeHtml(preview) + '</span>' +
                '</div>' +
                '<div class="local-zoomchat-conv-meta">' +
                (user.lastmessagetime
                    ? '<div class="local-zoomchat-conv-time">'
                    + formatRelativeTime(user.lastmessagetime)
                    + '</div>'
                    : '') +
                '<div class="local-zoomchat-conv-unread">' + (user.unreadcount || '') + '</div>' +
                '</div>';

            item.addEventListener('click', () => selectConversation(user.id, user.name));
            item.addEventListener('keydown', (e) => {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    selectConversation(user.id, user.name);
                }
            });

            convListEl.appendChild(item);
        });

        if (selectedPartnerId) {
            selectConversation(selectedPartnerId, '');
        } else if (data.users.length > 0) {
            selectConversation(data.users[0].id, data.users[0].name);
        }
    } catch (ex) {
        convListEl.innerHTML = '<div class="local-zoomchat-empty">' +
            escapeHtml(strErrorLoad) + '</div>';
    }
};

// --- Chat ---

const selectConversation = (partnerId, partnerName) => {
    selectedPartnerId = partnerId;

    convListEl.querySelectorAll('.local-zoomchat-conv-item').forEach((el) => {
        el.classList.toggle('active', parseInt(el.dataset.userid) === partnerId);
    });

    convListEl.closest('#local-zoomchat-convlist').classList.add('local-zoomchat-convlist-hidden');
    backBtn.style.display = 'inline-block';

    messagesList.innerHTML = '';
    messageInput.disabled = false;
    sendBtn.disabled = false;
    attachBtn.disabled = false;
    messageInput.focus();

    if (partnerName) {
        convHeader.textContent = escapeHtml(partnerName);
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
            data.messages.forEach((msg) => renderMessage(msg));
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

const renderMessage = (msg) => {
    const li = document.createElement('li');
    li.className = 'local-zoomchat-msg' +
        (msg.mymessage ? ' local-zoomchat-msg-mine' : ' local-zoomchat-msg-theirs');
    if (msg.id) {
        li.dataset.messageid = msg.id;
    }
    if (msg.timestamp) {
        li.dataset.timestamp = msg.timestamp;
    }
    li.innerHTML =
        '<div class="local-zoomchat-bubble">' + msg.message + '</div>' +
        '<div class="local-zoomchat-msg-time">' + getTimeStr(msg.timestamp) + '</div>';
    messagesList.appendChild(li);

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

const renderAttachmentPreviews = () => {
    previewContainer.innerHTML = '';
    pendingAttachments.forEach((att) => {
        const thumb = document.createElement('span');
        thumb.className = 'd-inline-block border rounded overflow-hidden position-relative local-zoomchat-attach-thumb';
        thumb.innerHTML = att.html;
        const removeBtn = document.createElement('button');
        removeBtn.type = 'button';
        removeBtn.className = 'btn-close position-absolute top-0 end-0 m-1 local-zoomchat-attach-remove';
        removeBtn.setAttribute('aria-label', strRemove);
        removeBtn.addEventListener('click', () => {
            const idx = pendingAttachments.indexOf(att);
            if (idx !== -1) {
                pendingAttachments.splice(idx, 1);
                renderAttachmentPreviews();
            }
        });
        thumb.appendChild(removeBtn);
        previewContainer.appendChild(thumb);
    });
};

// --- Realtime ---

const handleRealtimeEvent = (eventData) => {
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
        renderMessage({
            id: payload.messageid,
            message: payload.message,
            timestamp: payload.timestamp,
            mymessage: false,
        });
        scrollMessagesDown();
        clearUnreadForPartner(payload.from_userid);
    } else {
        showToast(payload);
        const item = convListEl.querySelector(
            '[data-userid="' + payload.from_userid + '"]'
        );
        if (item) {
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
    config = cfg;

    window.__localZoomchatLoaded = true;

    try {
        [
            strZoomchat,
            strNoconversations,
            strMessage,
            strSend,
            strEnter,
            strSelect,
            strAttach,
            strSending,
            strError,
            strErrorLoad,
            strErrorSend,
            strUnknown,
            strImage,
            strFile,
            strNew,
            strClose,
            strRemove,
        ] = await getStrings([
            {key: 'pluginname', component: 'local_zoomchat'},
            {key: 'noconversations', component: 'local_zoomchat'},
            {key: 'message', component: 'local_zoomchat'},
            {key: 'send', component: 'local_zoomchat'},
            {key: 'entermessage', component: 'local_zoomchat'},
            {key: 'selectcontact', component: 'local_zoomchat'},
            {key: 'attachimage', component: 'local_zoomchat'},
            {key: 'sending', component: 'local_zoomchat'},
            {key: 'error', component: 'core'},
            {key: 'error:loadfailed', component: 'local_zoomchat'},
            {key: 'error:sendfailed', component: 'local_zoomchat'},
            {key: 'unknownuser', component: 'local_zoomchat'},
            {key: 'image', component: 'local_zoomchat'},
            {key: 'file', component: 'local_zoomchat'},
            {key: 'newmessage', component: 'local_zoomchat'},
            {key: 'close', component: 'local_zoomchat'},
            {key: 'remove', component: 'local_zoomchat'},
        ]);
    } catch (error) {
        strZoomchat = 'Zoom Chat';
        strNoconversations = 'No conversations';
        strMessage = 'Message';
        strSend = 'Send';
        strEnter = 'Enter your message';
        strSelect = 'Select a contact';
        strAttach = 'Attach image';
        strSending = 'Sending...';
        strError = 'Error';
        strErrorLoad = 'Failed to load messages.';
        strErrorSend = 'Failed to send message.';
        strUnknown = 'Unknown user';
        strImage = 'Image';
        strFile = 'File';
        strNew = 'New message';
        strClose = 'Close';
        strRemove = 'Remove';
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
