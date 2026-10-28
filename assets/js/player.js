(function () {
    'use strict';

    var playlist = Array.isArray(window.PLAYLIST) ? window.PLAYLIST : [];
    var pendingList = Array.isArray(window.PENDING_LIST) ? window.PENDING_LIST : [];
    var csrfToken = window.CSRF_TOKEN || '';

    var FALLBACK_DURATION = 300;
    var BUFFER_SECONDS = 5;
    var LOAD_FALLBACK_MS = 8000;
    var PLAYER_START_DELAY_MS = 2000;

    var iframe = document.getElementById('biliFrame');
    var overlay = document.getElementById('startOverlay');
    var startBtn = document.getElementById('startBtn');
    var playlistEl = document.getElementById('playlist');
    var playlistFilterEl = document.getElementById('playlistFilter');
    var playlistCountEl = document.getElementById('playlistCount');
    var completedPanel = document.getElementById('completedPanel');
    var completedList = document.getElementById('completedList');
    var completedCountEl = document.getElementById('completedCount');
    var nowClassEl = document.getElementById('nowClass');
    var nowTitleEl = document.getElementById('nowTitle');
    var playStateEl = document.getElementById('playState');
    var songCounterEl = document.getElementById('songCounter');
    var autoNextEl = document.getElementById('autoNext');
    var completeBtn = document.getElementById('completeBtn');
    var clockEl = document.getElementById('clock');
    var noticeBoard = document.getElementById('noticeBoard');
    var currentRemarkRow = document.getElementById('currentRemarkRow');
    var currentRemarkText = document.getElementById('currentRemarkText');
    var nextRemarkRow = document.getElementById('nextRemarkRow');
    var nextRemarkText = document.getElementById('nextRemarkText');

    var currentIndex = -1;
    var started = false;
    var autoNext = true;
    var elapsed = 0;
    var duration = FALLBACK_DURATION;
    var tickTimer = null;
    var tickPending = false;
    var tickFallback = null;
    var tickStartTimer = null;
    var playQueue = [];
    var completedSongs = [];
    var classFilter = 'all';

    function rebuildQueues() {
        playQueue = playlist.filter(function (song) {
            return !song.completed;
        });
        completedSongs = playlist.filter(function (song) {
            return song.completed;
        });
    }

    /* ---------------- 审核相关元素 ---------------- */

    var reviewModal = document.getElementById('reviewModal');
    var reviewLogin = document.getElementById('reviewLogin');
    var reviewWorkspace = document.getElementById('reviewWorkspace');
    var reviewSummary = document.getElementById('reviewSummary');
    var reviewFrame = document.getElementById('reviewFrame');
    var reviewerNameInput = document.getElementById('reviewerName');
    var reviewerNoInput = document.getElementById('reviewerNo');
    var reviewLoginError = document.getElementById('reviewLoginError');
    var reviewError = document.getElementById('reviewError');
    var reviewStartBtn = document.getElementById('reviewStartBtn');
    var reviewSkipBtn = document.getElementById('reviewSkipBtn');
    var reviewCloseBtn = document.getElementById('reviewCloseBtn');
    var reviewApproveBtn = document.getElementById('reviewApproveBtn');
    var reviewRejectBtn = document.getElementById('reviewRejectBtn');
    var reviewPositionEl = document.getElementById('reviewPosition');
    var reviewTitleEl = document.getElementById('reviewTitle');
    var reviewMetaEl = document.getElementById('reviewMeta');
    var reviewRemarkInput = document.getElementById('reviewRemarkInput');
    var reviewLinkEl = document.getElementById('reviewLink');
    var reviewBvidInput = document.getElementById('reviewBvidInput');
    var reviewLoadVideoBtn = document.getElementById('reviewLoadVideoBtn');
    var reviewBvidHint = document.getElementById('reviewBvidHint');
    var reviewRejectReason = document.getElementById('reviewRejectReason');
    var reviewVideoEmpty = document.getElementById('reviewVideoEmpty');
    var reviewSummaryText = document.getElementById('reviewSummaryText');
    var rejectedList = document.getElementById('rejectedList');
    var copyRejectedBtn = document.getElementById('copyRejectedBtn');
    var reviewFinishBtn = document.getElementById('reviewFinishBtn');
    var reviewPendingCountEl = document.getElementById('reviewPendingCount');
    var reviewLoginCountEl = document.getElementById('reviewLoginCount');
    var pendingCountBadge = document.getElementById('pendingCountBadge');
    var openReviewBtn = document.getElementById('openReviewBtn');

    var reviewStarted = false;
    var reviewFinished = false;
    var reviewIndex = 0;
    var reviewBusy = false;
    var reviewResults = [];
    var reviewerNameValue = '';
    var reviewerNoValue = '';

    /* ---------------- 通用工具 ---------------- */

    function formatTime(seconds) {
        var value = Math.max(0, Math.floor(seconds));
        var minutes = Math.floor(value / 60);
        var rest = value % 60;
        return minutes + ':' + (rest < 10 ? '0' : '') + rest;
    }

    function buildPlayerUrl(bvid, startAt) {
        // 使用 B 站官方外链播放器的布尔参数，autoplay=true 才会在下一首加载时尝试自动播放。
        var url = 'https://player.bilibili.com/player.html?bvid=' + encodeURIComponent(bvid) +
            '&page=1&high_quality=1&danmaku=0&autoplay=true&poster=0';
        if (startAt && startAt > 0) {
            url += '&t=' + Math.floor(startAt);
        }
        return url + '&_r=' + Date.now();
    }

    function clearTick() {
        if (tickTimer !== null) {
            window.clearInterval(tickTimer);
            tickTimer = null;
        }
    }

    function cancelPendingTick() {
        tickPending = false;
        if (tickFallback !== null) {
            window.clearTimeout(tickFallback);
            tickFallback = null;
        }
        if (tickStartTimer !== null) {
            window.clearTimeout(tickStartTimer);
            tickStartTimer = null;
        }
    }

    function scheduleTick() {
        if (tickStartTimer !== null) {
            window.clearTimeout(tickStartTimer);
        }
        // 等播放器完成起播缓冲后再计时，避免自动切歌过早。
        tickStartTimer = window.setTimeout(function () {
            tickStartTimer = null;
            if (!started) {
                return;
            }
            startTick();
        }, PLAYER_START_DELAY_MS);
    }

    function waitForPlayerThenTick() {
        cancelPendingTick();
        tickPending = true;
        tickFallback = window.setTimeout(function () {
            if (!tickPending) {
                return;
            }
            tickPending = false;
            tickFallback = null;
            scheduleTick();
        }, LOAD_FALLBACK_MS);
    }

    iframe.addEventListener('load', function () {
        if (!tickPending) {
            return;
        }
        tickPending = false;
        if (tickFallback !== null) {
            window.clearTimeout(tickFallback);
            tickFallback = null;
        }
        scheduleTick();
    });

    /* ---------------- 进度记录 ---------------- */

    function saveProgress(seconds, useBeacon) {
        if (currentIndex < 0) {
            return;
        }
        var song = playQueue[currentIndex];
        if (!song || !song.id) {
            return;
        }

        var value = Math.max(0, Math.floor(seconds || 0));
        if (duration > 0 && value >= duration - 3) {
            value = 0;
        }
        song.progress = value;

        var form = new FormData();
        form.append('action', 'progress');
        form.append('id', song.id);
        form.append('seconds', value);
        form.append('csrf_token', csrfToken);

        if (useBeacon && navigator.sendBeacon) {
            navigator.sendBeacon('api/song.php', form);
            return;
        }

        fetch('api/song.php', {
            method: 'POST',
            body: form,
            keepalive: true
        }).catch(function () {
            // 进度记录失败不打断播放。
        });
    }

    /* ---------------- 播放列表 ---------------- */

    function collectClassStats() {
        var stats = [];
        playQueue.forEach(function (song) {
            for (var i = 0; i < stats.length; i += 1) {
                if (stats[i].name === song.class) {
                    stats[i].count += 1;
                    return;
                }
            }
            stats.push({ name: song.class, count: 1 });
        });
        return stats;
    }

    function createFilterButton(label, count, value) {
        var button = document.createElement('button');
        button.type = 'button';
        button.className = 'playlist-filter-btn' + (classFilter === value ? ' is-active' : '');
        button.textContent = label + ' ' + count;
        button.addEventListener('click', function () {
            if (classFilter === value) {
                return;
            }
            classFilter = value;
            renderPlaylist();
            highlightPlaylist();
        });
        return button;
    }

    function renderPlaylistFilter() {
        if (!playlistFilterEl) {
            return;
        }

        var stats = collectClassStats();
        var filterExists = false;
        for (var i = 0; i < stats.length; i += 1) {
            if (stats[i].name === classFilter) {
                filterExists = true;
                break;
            }
        }
        if (!filterExists) {
            classFilter = 'all';
        }

        playlistFilterEl.innerHTML = '';
        playlistFilterEl.hidden = stats.length < 2;
        if (stats.length < 2) {
            classFilter = 'all';
            return;
        }

        playlistFilterEl.appendChild(createFilterButton('全部', playQueue.length, 'all'));
        stats.forEach(function (item) {
            playlistFilterEl.appendChild(createFilterButton(item.name, item.count, item.name));
        });
    }

    function renderPlaylist() {
        playlistEl.innerHTML = '';
        completedList.innerHTML = '';

        renderPlaylistFilter();

        var visibleCount = 0;

        playQueue.forEach(function (song, index) {
            if (classFilter !== 'all' && song.class !== classFilter) {
                return;
            }
            visibleCount += 1;

            var item = document.createElement('li');
            item.className = 'playlist-item';
            item.setAttribute('role', 'button');
            item.setAttribute('tabindex', '0');
            item.setAttribute('data-index', String(index));

            var number = document.createElement('span');
            number.className = 'playlist-num';
            number.textContent = String(index + 1).padStart(2, '0');

            var info = document.createElement('span');
            info.className = 'playlist-info';

            var title = document.createElement('strong');
            title.textContent = '《' + song.title + '》';

            var meta = document.createElement('small');
            meta.textContent = song.class;

            info.appendChild(title);
            info.appendChild(meta);

            var side = document.createElement('span');
            side.className = 'playlist-side';

            var itemDuration = document.createElement('span');
            itemDuration.className = 'playlist-duration';
            if (song.progress > 0) {
                itemDuration.textContent = '续 ' + formatTime(song.progress);
            } else {
                itemDuration.textContent = song.duration > 0 ? formatTime(song.duration) : '—';
            }
            side.appendChild(itemDuration);

            if (song.remark) {
                var note = document.createElement('span');
                note.className = 'playlist-note';
                note.textContent = '备注';
                note.title = song.remark;
                side.appendChild(note);
            }

            item.appendChild(number);
            item.appendChild(info);
            item.appendChild(side);

            item.addEventListener('click', function () {
                if (currentIndex === index) {
                    return;
                }
                if (started) {
                    saveProgress(elapsed, false);
                }
                startPlayback();
                loadSong(index);
            });
            item.addEventListener('keydown', function (event) {
                if (event.key === 'Enter' || event.key === ' ') {
                    event.preventDefault();
                    if (currentIndex === index) {
                        return;
                    }
                    if (started) {
                        saveProgress(elapsed, false);
                    }
                    startPlayback();
                    loadSong(index);
                }
            });

            playlistEl.appendChild(item);
        });

        if (visibleCount === 0) {
            var empty = document.createElement('li');
            empty.className = 'playlist-empty';
            empty.textContent = playQueue.length === 0 ? '暂无可播放曲目' : '该班级暂无待播放曲目';
            playlistEl.appendChild(empty);
        }

        completedSongs.forEach(function (song) {
            var item = document.createElement('li');
            item.className = 'completed-item';

            var info = document.createElement('span');
            info.className = 'completed-info';

            var title = document.createElement('strong');
            title.textContent = '《' + song.title + '》';

            var meta = document.createElement('small');
            meta.textContent = song.class + (song.completedAt ? ' · ' + song.completedAt : '');

            info.appendChild(title);
            info.appendChild(meta);

            var undo = document.createElement('button');
            undo.type = 'button';
            undo.className = 'completed-undo';
            undo.textContent = '取消完成';
            undo.addEventListener('click', function (event) {
                event.stopPropagation();
                undoComplete(song);
            });

            item.appendChild(info);
            item.appendChild(undo);
            completedList.appendChild(item);
        });

        if (playlistCountEl) {
            playlistCountEl.textContent = String(visibleCount);
        }
        if (completedCountEl) {
            completedCountEl.textContent = String(completedSongs.length);
        }
        if (completedPanel) {
            completedPanel.hidden = completedSongs.length === 0;
        }
    }

    function undoComplete(song) {
        var form = new FormData();
        form.append('action', 'uncomplete');
        form.append('id', song.id);
        form.append('csrf_token', csrfToken);

        playStateEl.textContent = '正在取消完成状态...';

        fetch('api/song.php', {
            method: 'POST',
            body: form
        }).then(function (response) {
            return response.json();
        }).then(function (data) {
            if (!data.ok) {
                playStateEl.textContent = data.message || '取消完成失败，请重试';
                return;
            }

            var currentSongId = playQueue[currentIndex] ? playQueue[currentIndex].id : null;
            song.completed = false;
            song.completedAt = '';
            song.progress = 0;
            rebuildQueues();

            if (!started) {
                window.location.reload();
                return;
            }

            currentIndex = -1;
            if (currentSongId !== null) {
                for (var i = 0; i < playQueue.length; i += 1) {
                    if (playQueue[i].id === currentSongId) {
                        currentIndex = i;
                        break;
                    }
                }
            }

            renderPlaylist();
            highlightPlaylist();
            updateNotes();
            updateCompleteButton();
            playStateEl.textContent = '已取消完成，曲目已恢复到待播放列表';
        }).catch(function () {
            playStateEl.textContent = '取消完成失败，请重试';
        });
    }

    function highlightPlaylist() {
        var items = playlistEl.querySelectorAll('.playlist-item');
        for (var i = 0; i < items.length; i += 1) {
            var isActive = Number(items[i].getAttribute('data-index')) === currentIndex;
            items[i].classList.toggle('is-active', isActive);
            if (isActive) {
                items[i].setAttribute('aria-current', 'true');
                if (typeof items[i].scrollIntoView === 'function') {
                    items[i].scrollIntoView({ block: 'nearest', behavior: 'smooth' });
                }
            } else {
                items[i].removeAttribute('aria-current');
            }
        }
    }

    /* ---------------- 备注与完成状态 ---------------- */

    function updateNotes() {
        if (currentIndex < 0) {
            currentRemarkRow.hidden = true;
            nextRemarkRow.hidden = true;
            noticeBoard.hidden = true;
            return;
        }

        var song = playQueue[currentIndex];
        var hasCurrent = !!(song && song.remark);
        var next = playQueue[currentIndex + 1];
        var hasNext = !!(next && next.remark);

        if (hasCurrent) {
            currentRemarkText.textContent = song.remark;
        }
        currentRemarkRow.hidden = !hasCurrent;

        if (hasNext) {
            nextRemarkText.textContent = '下一首《' + next.title + '》（' + next.class + '）：' + next.remark;
        }
        nextRemarkRow.hidden = !hasNext;

        noticeBoard.hidden = !(hasCurrent || hasNext);
    }

    function updateCompleteButton() {
        var song = playQueue[currentIndex];
        if (!song) {
            completeBtn.disabled = true;
            completeBtn.textContent = '标记完成';
            return;
        }
        if (song.completed) {
            completeBtn.disabled = true;
            completeBtn.textContent = '已完成';
        } else {
            completeBtn.disabled = false;
            completeBtn.textContent = '标记完成';
        }
    }

    /* ---------------- 正常播放 ---------------- */

    function startPlayback() {
        if (started || playQueue.length === 0) {
            return;
        }
        if (overlay) {
            overlay.classList.add('is-hidden');
        }
    }

    function startTick() {
        clearTick();
        tickTimer = window.setInterval(function () {
            if (!started) {
                return;
            }

            elapsed += 1;

            if (elapsed % 5 === 0) {
                saveProgress(elapsed, false);
            }

            if (elapsed >= duration + BUFFER_SECONDS) {
                if (autoNext) {
                    saveProgress(0, false);
                    goNext(true);
                } else {
                    saveProgress(0, false);
                    clearTick();
                    playStateEl.textContent = '本首已结束，等待手动切歌';
                }
            }
        }, 1000);
    }

    function loadSong(index, startOverride) {
        if (index < 0 || index >= playQueue.length) {
            return;
        }

        currentIndex = index;
        started = true;

        var song = playQueue[index];
        duration = song.duration > 0 ? song.duration : FALLBACK_DURATION;

        var startAt = typeof startOverride === 'number'
            ? startOverride
            : (song.progress > 0 ? song.progress : 0);
        if (startAt >= duration - 3) {
            startAt = 0;
        }

        elapsed = startAt;
        song.progress = startAt;

        nowClassEl.textContent = song.class;
        nowTitleEl.textContent = '《' + song.title + '》';
        songCounterEl.textContent = (index + 1) + ' / ' + playQueue.length;
        playStateEl.textContent = song.duration > 0
            ? '播放中'
            : '播放中（未获取时长，按 5 分钟估算）';

        updateNotes();
        updateCompleteButton();
        highlightPlaylist();

        clearTick();
        cancelPendingTick();
        waitForPlayerThenTick();
        iframe.src = buildPlayerUrl(song.bvid, startAt);
    }

    function goNext(isAuto) {
        if (playQueue.length === 0) {
            return;
        }
        if (currentIndex < 0) {
            loadSong(0);
            return;
        }

        if (!isAuto) {
            saveProgress(elapsed, false);
        }

        if (currentIndex < playQueue.length - 1) {
            loadSong(currentIndex + 1);
            return;
        }

        if (isAuto) {
            clearTick();
            cancelPendingTick();
            started = false;
            saveProgress(0, false);
            playStateEl.textContent = '本轮播放完毕';
        } else {
            // 已经是最后一首，保持当前播放，不打断画面和声音。
            playStateEl.textContent = '已经是最后一首';
        }
    }

    function goPrev() {
        if (playQueue.length === 0) {
            return;
        }
        if (started) {
            saveProgress(elapsed, false);
        }
        if (currentIndex > 0) {
            loadSong(currentIndex - 1);
        } else {
            loadSong(Math.max(0, currentIndex));
        }
    }

    function replay() {
        if (playQueue.length === 0) {
            return;
        }
        var index = currentIndex >= 0 ? currentIndex : 0;
        playQueue[index].progress = 0;
        loadSong(index, 0);
    }

    if (startBtn) {
        startBtn.addEventListener('click', function () {
            startPlayback();
            loadSong(currentIndex >= 0 ? currentIndex : 0);
        });
    }

    document.getElementById('nextBtn').addEventListener('click', function () {
        startPlayback();
        if (currentIndex < 0) {
            loadSong(0);
        } else {
            goNext(false);
        }
    });

    document.getElementById('prevBtn').addEventListener('click', function () {
        startPlayback();
        goPrev();
    });

    document.getElementById('replayBtn').addEventListener('click', function () {
        startPlayback();
        replay();
    });

    fullBtnSetup();

    function fullBtnSetup() {
        document.getElementById('fullBtn').addEventListener('click', function () {
            var target = document.getElementById('screenWrap');
            if (document.fullscreenElement) {
                document.exitFullscreen();
                return;
            }
            if (target && typeof target.requestFullscreen === 'function') {
                target.requestFullscreen();
            }
        });
    }

    autoNextEl.addEventListener('change', function () {
        autoNext = autoNextEl.checked;
        if (autoNext && started && elapsed >= duration + BUFFER_SECONDS) {
            goNext(true);
        }
    });

    completeBtn.addEventListener('click', function () {
        if (currentIndex < 0) {
            return;
        }
        var song = playQueue[currentIndex];
        if (!song || song.completed) {
            return;
        }

        completeBtn.disabled = true;
        playStateEl.textContent = '正在标记完成...';

        var form = new FormData();
        form.append('action', 'complete');
        form.append('id', song.id);
        form.append('csrf_token', csrfToken);

        fetch('api/song.php', {
            method: 'POST',
            body: form
        }).then(function (response) {
            return response.json();
        }).then(function (data) {
            if (!data.ok) {
                completeBtn.disabled = false;
                playStateEl.textContent = data.message || '标记失败，请重试';
                return;
            }

            var nextSong = playQueue[currentIndex + 1] || null;

            song.completed = true;
            song.completedAt = data.display || '';
            song.progress = 0;
            rebuildQueues();
            renderPlaylist();

            if (nextSong) {
                var nextIndex = -1;
                for (var i = 0; i < playQueue.length; i += 1) {
                    if (playQueue[i].id === nextSong.id) {
                        nextIndex = i;
                        break;
                    }
                }
                if (nextIndex >= 0) {
                    loadSong(nextIndex);
                    return;
                }
            }

            clearTick();
            cancelPendingTick();
            started = false;
            iframe.src = 'about:blank';
            currentIndex = -1;
            updateNotes();
            updateCompleteButton();

            if (playQueue.length === 0) {
                window.location.reload();
                return;
            }

            songCounterEl.textContent = '0 / ' + playQueue.length;
            playStateEl.textContent = '已标记完成，请从待播放列表选择下一首';
        }).catch(function () {
            completeBtn.disabled = false;
            playStateEl.textContent = '标记失败，请重试';
        });
    });

    document.getElementById('refreshBtn').addEventListener('click', function () {
        window.location.reload();
    });

    document.addEventListener('keydown', function (event) {
        if (!reviewModal.hidden) {
            return;
        }
        var tag = (event.target.tagName || '').toLowerCase();
        if (tag === 'input' || tag === 'textarea' || tag === 'select') {
            return;
        }
        if (event.key === 'ArrowRight') {
            event.preventDefault();
            startPlayback();
            if (currentIndex < 0) {
                loadSong(0);
            } else {
                goNext(false);
            }
        } else if (event.key === 'ArrowLeft') {
            event.preventDefault();
            startPlayback();
            goPrev();
        }
    });

    window.addEventListener('pagehide', function () {
        if (started) {
            saveProgress(elapsed, true);
        }
    });

    /* ---------------- 时钟 ---------------- */

    function updateClock() {
        if (clockEl) {
            clockEl.textContent = new Date().toLocaleTimeString('zh-CN', { hour12: false });
        }
    }

    /* ---------------- 大屏审核流程 ---------------- */

    function updatePendingDisplays() {
        var remaining = Math.max(0, pendingList.length - reviewIndex);
        if (pendingCountBadge) {
            pendingCountBadge.textContent = String(remaining);
        }
        if (reviewPendingCountEl) {
            reviewPendingCountEl.textContent = String(remaining);
        }
        if (reviewLoginCountEl) {
            reviewLoginCountEl.textContent = String(pendingList.length);
        }
    }

    function showReviewStage(stage) {
        reviewLogin.hidden = stage !== 'login';
        reviewWorkspace.hidden = stage !== 'workspace';
        reviewSummary.hidden = stage !== 'summary';
    }

    function stopMainForReview() {
        if (!started) {
            return;
        }
        saveProgress(elapsed, false);
        clearTick();
        cancelPendingTick();
        started = false;
        iframe.src = 'about:blank';
        if (overlay) {
            overlay.classList.remove('is-hidden');
        }
        playStateEl.textContent = '播放已暂停，等待审核';
    }

    function openReviewModal() {
        if (pendingList.length === 0) {
            return;
        }

        stopMainForReview();
        reviewModal.hidden = false;
        document.body.classList.add('modal-open');
        updatePendingDisplays();

        if (reviewFinished) {
            showReviewStage('summary');
        } else if (reviewStarted) {
            showReviewStage('workspace');
            loadReviewSong();
        } else {
            showReviewStage('login');
            window.setTimeout(function () {
                reviewerNameInput.focus();
            }, 50);
        }
    }

    function closeReviewModal() {
        if (reviewBusy) {
            return;
        }
        reviewModal.hidden = true;
        document.body.classList.remove('modal-open');
        reviewFrame.src = 'about:blank';
    }

    function validateReviewer() {
        var name = reviewerNameInput.value.trim();
        var no = reviewerNoInput.value.trim();

        if (name === '') {
            reviewLoginError.textContent = '请填写审核人姓名。';
            reviewLoginError.hidden = false;
            reviewerNameInput.focus();
            return false;
        }
        if (no === '') {
            reviewLoginError.textContent = '请填写审核人学号 / 工号。';
            reviewLoginError.hidden = false;
            reviewerNoInput.focus();
            return false;
        }

        reviewerNameValue = name;
        reviewerNoValue = no;
        reviewLoginError.hidden = true;
        return true;
    }

    function startReview() {
        if (!validateReviewer()) {
            return;
        }
        if (!reviewStarted) {
            reviewStarted = true;
            reviewFinished = false;
            reviewIndex = 0;
            reviewResults = [];
        }
        showReviewStage('workspace');
        loadReviewSong();
    }

    function setReviewButtonsDisabled(disabled) {
        reviewApproveBtn.disabled = disabled;
        reviewRejectBtn.disabled = disabled;
        reviewCloseBtn.disabled = disabled;
    }

    function loadReviewSong() {
        if (reviewIndex >= pendingList.length) {
            finishReview();
            return;
        }

        var song = pendingList[reviewIndex];
        showReviewStage('workspace');
        reviewBusy = false;
        setReviewButtonsDisabled(false);
        reviewError.hidden = true;

        reviewPositionEl.textContent = '第 ' + (reviewIndex + 1) + ' / ' + pendingList.length + ' 首';
        reviewTitleEl.textContent = '《' + song.title + '》';

        var uploader = song.uploaderName || '未填写';
        var uploaderNo = song.uploaderNo || '未填写';
        var meta = song.class + ' · 上传人：' + uploader + '（' + uploaderNo + '）';
        if (song.duration > 0) {
            meta += ' · 时长 ' + formatTime(song.duration);
        }
        reviewMetaEl.textContent = meta;
        if (reviewRemarkInput) {
            reviewRemarkInput.value = song.remark || '';
        }

        reviewBvidInput.value = song.bvid || '';
        reviewBvidHint.classList.remove('is-error');
        if (reviewRejectReason) {
            reviewRejectReason.value = '';
        }

        if (song.bvid) {
            reviewBvidHint.textContent = '已自动匹配视频，如不正确可修改后点击“加载视频”。';
            reviewLinkEl.href = 'https://www.bilibili.com/video/' + encodeURIComponent(song.bvid);
            reviewLinkEl.hidden = false;
            reviewVideoEmpty.hidden = true;
            reviewFrame.src = buildPlayerUrl(song.bvid, 0);
        } else {
            reviewBvidHint.textContent = '未匹配到播放链接，请粘贴 B 站链接或 BV 号后点击“加载视频”。';
            reviewLinkEl.hidden = true;
            reviewVideoEmpty.hidden = false;
            reviewFrame.src = 'about:blank';
        }

        updatePendingDisplays();
    }

    function loadReviewVideoByInput() {
        var value = reviewBvidInput.value.trim();
        var match = value.match(/BV[0-9A-Za-z]{10}/);

        if (!match) {
            reviewBvidHint.textContent = '未识别到 BV 号，请粘贴完整的 B 站链接或 BV 号。';
            reviewBvidHint.classList.add('is-error');
            return;
        }

        var bvid = match[0];
        reviewBvidHint.textContent = '已加载视频 ' + bvid + '，请试听。';
        reviewBvidHint.classList.remove('is-error');
        reviewVideoEmpty.hidden = true;
        reviewLinkEl.href = 'https://www.bilibili.com/video/' + encodeURIComponent(bvid);
        reviewLinkEl.hidden = false;
        reviewFrame.src = buildPlayerUrl(bvid, 0);
    }

    function submitReview(decision) {
        if (reviewBusy || reviewIndex >= pendingList.length) {
            return;
        }
        if (reviewerNameValue === '' || reviewerNoValue === '') {
            reviewStarted = false;
            showReviewStage('login');
            reviewLoginError.textContent = '请先填写审核人信息。';
            reviewLoginError.hidden = false;
            return;
        }

        var song = pendingList[reviewIndex];
        var rejectReason = reviewRejectReason ? reviewRejectReason.value.trim() : '';
        var remarkValue = reviewRemarkInput ? reviewRemarkInput.value.trim() : '';
        reviewBusy = true;
        setReviewButtonsDisabled(true);
        reviewError.hidden = true;
        reviewPositionEl.textContent = '正在保存审核结果...';

        var form = new FormData();
        form.append('id', song.id);
        form.append('decision', decision);
        form.append('reviewer_name', reviewerNameValue);
        form.append('reviewer_no', reviewerNoValue);
        form.append('bvid_input', reviewBvidInput.value.trim());
        form.append('reject_reason', rejectReason);
        form.append('remark', remarkValue);
        form.append('csrf_token', csrfToken);

        fetch('api/review.php', {
            method: 'POST',
            body: form
        }).then(function (response) {
            return response.json();
        }).then(function (data) {
            if (!data.ok) {
                reviewBusy = false;
                setReviewButtonsDisabled(false);
                reviewError.textContent = data.message || '审核保存失败，请重试。';
                reviewError.hidden = false;
                reviewPositionEl.textContent = '第 ' + (reviewIndex + 1) + ' / ' + pendingList.length + ' 首';
                return;
            }

            reviewResults.push({ song: song, decision: decision });
            song.remark = remarkValue;
            if (decision === 'reject') {
                song.rejectReason = rejectReason;
            } else {
                song.rejectReason = '';
            }
            reviewIndex += 1;
            reviewBusy = false;
            setReviewButtonsDisabled(false);
            loadReviewSong();
        }).catch(function () {
            reviewBusy = false;
            setReviewButtonsDisabled(false);
            reviewError.textContent = '网络异常，审核结果未保存，请重试。';
            reviewError.hidden = false;
            reviewPositionEl.textContent = '第 ' + (reviewIndex + 1) + ' / ' + pendingList.length + ' 首';
        });
    }

    function buildRejectedText(rejected) {
        var lines = [];
        var now = new Date();
        lines.push('未通过审核名单（' + now.toLocaleString('zh-CN', { hour12: false }) + '）');
        lines.push('');

        if (rejected.length === 0) {
            lines.push('本次审核没有未通过的曲目。');
            return lines.join('\n');
        }

        rejected.forEach(function (item, index) {
            var song = item.song;
            lines.push((index + 1) + '. ' + song.class + '《' + song.title + '》');
            lines.push('   上传人：' + (song.uploaderName || '未填写') + '（' + (song.uploaderNo || '未填写') + '）');
            lines.push('   链接：https://www.bilibili.com/video/' + song.bvid);
            if (song.remark) {
                lines.push('   备注：' + song.remark);
            }
            if (song.rejectReason) {
                lines.push('   拒绝原因：' + song.rejectReason);
            }
            lines.push('');
        });

        return lines.join('\n');
    }

    function finishReview() {
        reviewFinished = true;
        reviewIndex = pendingList.length;
        showReviewStage('summary');
        updatePendingDisplays();
        reviewFrame.src = 'about:blank';

        var approved = reviewResults.filter(function (item) {
            return item.decision === 'approve';
        });
        var rejected = reviewResults.filter(function (item) {
            return item.decision === 'reject';
        });

        reviewSummaryText.textContent = '本次共审核 ' + reviewResults.length + ' 首，通过 ' +
            approved.length + ' 首，拒绝 ' + rejected.length + ' 首。';
        rejectedList.value = buildRejectedText(rejected);
        copyRejectedBtn.textContent = '复制名单';
    }

    reviewStartBtn.addEventListener('click', startReview);
    reviewLoadVideoBtn.addEventListener('click', loadReviewVideoByInput);
    reviewBvidInput.addEventListener('keydown', function (event) {
        if (event.key === 'Enter') {
            event.preventDefault();
            loadReviewVideoByInput();
        }
    });
    reviewApproveBtn.addEventListener('click', function () {
        submitReview('approve');
    });
    reviewRejectBtn.addEventListener('click', function () {
        submitReview('reject');
    });
    reviewSkipBtn.addEventListener('click', function () {
        closeReviewModal();
        if (playQueue.length > 0 && !started) {
            startPlayback();
            loadSong(currentIndex >= 0 ? currentIndex : 0);
        }
    });
    reviewCloseBtn.addEventListener('click', closeReviewModal);
    openReviewBtn.addEventListener('click', openReviewModal);

    copyRejectedBtn.addEventListener('click', function () {
        var text = rejectedList.value;
        var done = function () {
            copyRejectedBtn.textContent = '已复制';
            window.setTimeout(function () {
                copyRejectedBtn.textContent = '复制名单';
            }, 1800);
        };
        var fallback = function () {
            rejectedList.select();
            try {
                document.execCommand('copy');
                done();
            } catch (error) {
                copyRejectedBtn.textContent = '请手动复制';
            }
        };

        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(done).catch(fallback);
        } else {
            fallback();
        }
    });

    reviewFinishBtn.addEventListener('click', function () {
        reviewFrame.src = 'about:blank';
        window.location.reload();
    });

    /* ---------------- 初始化 ---------------- */

    rebuildQueues();
    renderPlaylist();
    updateCompleteButton();
    updateNotes();
    updateClock();
    window.setInterval(updateClock, 1000);

    if (playQueue.length === 0) {
        songCounterEl.textContent = '0 / 0';
        playStateEl.textContent = '暂无可播放曲目';
    }

    if (pendingList.length > 0) {
        openReviewModal();
    }
})();
