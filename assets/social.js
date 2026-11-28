/* socialbored — progressive enhancement for social actions. */
(function () {
    'use strict';

    var bootEl = document.getElementById('socialbored-boot');
    if (!bootEl) return;

    var boot;
    try {
        boot = JSON.parse(bootEl.getAttribute('data-boot') || '{}');
    } catch (e) {
        return;
    }

    var endpoints = boot.endpoints || {};
    var labels = boot.labels || {};

    function toast(message) {
        if (!message) return;
        var el = document.getElementById('socialbored-toast');
        if (!el) {
            el = document.createElement('div');
            el.id = 'socialbored-toast';
            el.className = 'social-toast';
            el.setAttribute('role', 'status');
            el.setAttribute('aria-live', 'polite');
            document.body.appendChild(el);
        }
        el.textContent = message;
        el.classList.add('is-visible');
        window.clearTimeout(el._timer);
        el._timer = window.setTimeout(function () {
            el.classList.remove('is-visible');
        }, 2600);
    }

    function endpointFor(action) {
        if (action === 'react') return endpoints.react;
        if (action === 'repost') return endpoints.repost;
        if (action === 'bookmark') return endpoints.bookmark;
        if (action === 'follow') return endpoints.follow;
        if (action === 'unfollow') return endpoints.unfollow;
        return '';
    }

    function setActive(button, active) {
        if (!button) return;
        button.classList.toggle('is-active', !!active);
        button.setAttribute('aria-pressed', active ? 'true' : 'false');
    }

    function updateReaction(button, data) {
        if (!button) return;
        setActive(button, data.active);
        var icon = button.querySelector('i');
        if (icon) {
            icon.classList.toggle('fas', !!data.active);
            icon.classList.toggle('far', !data.active);
        }
        var count = button.querySelector('[data-social-count="like"]');
        if (count) {
            count.textContent = data.count != null ? String(data.count) : '0';
        }
    }

    function updateToggle(button, data, name) {
        if (!button) return;
        setActive(button, data.active);
        var icon = button.querySelector('i');
        if (icon && name === 'bookmark') {
            icon.classList.toggle('fas', !!data.active);
            icon.classList.toggle('far', !data.active);
        }
        var count = button.querySelector('[data-social-count="' + name + '"]');
        if (count) {
            count.textContent = data.count != null ? String(data.count) : '0';
        }
    }

    function updateFollow(button, following) {
        if (!button) return;
        var label = following ? (labels.following || 'Following') : (labels.follow || 'Follow');
        var iconClass = following ? 'fa-user-check' : 'fa-user-plus';
        var span = button.querySelector('span');
        if (span) {
            span.textContent = label;
            var icon = button.querySelector('i');
            if (icon) {
                icon.className = 'fas ' + iconClass;
            }
        } else {
            button.innerHTML = '<i class="fas ' + iconClass + '"></i>' + label;
        }
        button.classList.toggle('btn-brand', !following);
        button.classList.toggle('btn-outline-soft', following);

        // A second click must call the opposite endpoint, otherwise the form
        // would keep POSTing /follow and never unfollow.
        var form = button.closest('form');
        if (form) {
            var next = following ? 'unfollow' : 'follow';
            if (endpoints[next]) {
                form.setAttribute('data-social-action', next);
                form.setAttribute('action', endpoints[next]);
            }
        }
    }

    function submitSocial(action, formData, button) {
        var endpoint = endpointFor(action);
        if (!endpoint) return;

        fetch(endpoint, {
            method: 'POST',
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: formData,
            credentials: 'same-origin'
        }).then(function (response) {
            return response.json().then(function (data) {
                return { ok: response.ok, status: response.status, data: data };
            });
        }).then(function (result) {
            if (result.data && result.data.csrf) {
                boot.csrf = result.data.csrf;
            }
            if (!result.ok || !result.data || !result.data.ok) {
                toast(labels.error || 'Something went wrong');
                return;
            }
            if (action === 'react') {
                updateReaction(button, result.data);
            } else if (action === 'repost' || action === 'bookmark') {
                updateToggle(button, result.data, action);
            } else if (action === 'follow' || action === 'unfollow') {
                var following = action === 'follow' ? true : false;
                updateFollow(button, following);
            }
        }).catch(function () {
            toast(labels.error || 'Something went wrong');
        });
    }

    document.addEventListener('submit', function (event) {
        var form = event.target.closest('form[data-social-action]');
        if (!form) return;
        event.preventDefault();

        var action = form.getAttribute('data-social-action');
        var button = form.querySelector('button[data-social-button]') || form.querySelector('button');
        var data = new FormData(form);
        data.set('csrf_token', boot.csrf || data.get('csrf_token') || '');
        submitSocial(action, data, button);
    });

    document.addEventListener('click', function (event) {
        var copy = event.target.closest('[data-social-copy]');
        if (copy) {
            event.preventDefault();
            var value = copy.getAttribute('data-social-copy') || '';
            var url = value.indexOf('http') === 0 ? value : (window.location.origin + value);
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(url).then(function () {
                    toast(labels.linkCopied || labels.copied || 'Link copied');
                }).catch(function () {
                    toast(url);
                });
            } else {
                toast(url);
            }
            return;
        }

        var actionButton = event.target.closest('[data-social-action-button]');
        if (actionButton) {
            event.preventDefault();
            var action = actionButton.getAttribute('data-action');
            var type = actionButton.getAttribute('data-target-type') || 'thread';
            var id = actionButton.getAttribute('data-target-id') || '0';
            var data = new FormData();
            data.append('csrf_token', boot.csrf || '');
            if (action === 'react') {
                data.append('target_type', type);
                data.append('target_id', id);
                data.append('kind', 'like');
            } else if (action === 'repost' || action === 'bookmark') {
                data.append('thread_id', id);
            }
            submitSocial(action, data, actionButton);
        }
    });

    function socialIconButton(action, targetType, targetId, icon, count, active) {
        var button = document.createElement('button');
        button.type = 'button';
        button.className = 'social-action' + (active ? ' is-active' : '');
        button.setAttribute('data-social-action-button', '');
        button.setAttribute('data-action', action);
        button.setAttribute('data-target-type', targetType);
        button.setAttribute('data-target-id', targetId);
        if (active) {
            button.setAttribute('aria-pressed', 'true');
        }
        var i = document.createElement('i');
        i.className = (action === 'react' ? (active ? 'fas' : 'far') : 'fas') + ' ' + icon;
        button.appendChild(i);
        if (count !== null) {
            var span = document.createElement('span');
            span.textContent = String(count);
            button.appendChild(span);
        }
        return button;
    }

    function injectThreadActions() {
        if (!boot.loggedIn) return;
        var match = window.location.pathname.match(/\/thread\/(\d+)/);
        if (!match) return;

        var posts = Array.prototype.slice.call(document.querySelectorAll('article.post[data-post-id]'));
        if (!posts.length) return;

        var threadId = match[1];
        var postIds = posts.filter(function (el) {
            return el.getAttribute('data-is-op') !== '1';
        }).map(function (el) {
            return el.getAttribute('data-post-id');
        });

        var url;
        try {
            url = new URL(endpoints.state, window.location.origin);
        } catch (e) {
            return;
        }
        url.searchParams.set('thread_id', threadId);
        if (postIds.length) {
            url.searchParams.set('post_ids', postIds.join(','));
        }

        fetch(url.toString(), {
            headers: { 'Accept': 'application/json' },
            credentials: 'same-origin'
        }).then(function (response) {
            return response.ok ? response.json() : null;
        }).then(function (state) {
            if (!state || !state.ok) return;

            var features = boot.features || {};

            posts.forEach(function (post) {
                var isOp = post.getAttribute('data-is-op') === '1';
                var targetId = post.getAttribute('data-post-id');
                var main = post.querySelector('.post-main');
                if (!main) return;
                if (main.querySelector('.social-post-actions')) return;

                var bar = document.createElement('div');
                bar.className = 'social-post-actions social-actions';

                if (features.reactions !== false) {
                    var reactionState = isOp ? (state.thread && state.thread.reactions) : (state.posts && state.posts[targetId]);
                    var count = reactionState && reactionState.count != null ? reactionState.count : 0;
                    var active = reactionState && reactionState.kind;
                    bar.appendChild(socialIconButton('react', isOp ? 'thread' : 'post', targetId, 'fa-heart', count, !!active));
                }

                if (isOp && features.reposts !== false) {
                    bar.appendChild(socialIconButton('repost', 'thread', targetId, 'fa-retweet', state.thread.reposts ? state.thread.reposts.count : 0, !!(state.thread.reposts && state.thread.reposts.active)));
                }
                if (isOp && features.bookmarks !== false) {
                    var bookmark = state.thread && state.thread.bookmarks;
                    bar.appendChild(socialIconButton('bookmark', 'thread', targetId, 'fa-bookmark', null, !!(bookmark && bookmark.active)));
                }

                if (!bar.children.length) {
                    return;
                }

                var anchor = post.querySelector('.post-attachments') || post.querySelector('.post-body');
                if (anchor && anchor.parentNode) {
                    anchor.parentNode.insertBefore(bar, anchor.nextSibling);
                } else {
                    main.appendChild(bar);
                }
            });
        }).catch(function () {});
    }

    function repointMobileCompose() {
        if (!endpoints.compose) return;

        if (boot.mode !== 'social') {
            // Outside full social mode keep the original behaviour exactly:
            // every New Thread link is repointed to the social composer.
            document.querySelectorAll('a.mobile-tab[href*="new-thread"], a.nav-link[href*="new-thread"], .footer-list a[href*="new-thread"], .btn-new-thread[href*="new-thread"]').forEach(function (link) {
                link.setAttribute('href', endpoints.compose);
            });
            return;
        }

        // Full social mode: the mobile tab bar already has the plugin's own New
        // post action. Hide the core New Thread tab instead of turning it into a
        // second New post button next to it.
        document.querySelectorAll('a.mobile-tab[href*="new-thread"]').forEach(function (link) {
            link.style.display = 'none';
            link.setAttribute('aria-hidden', 'true');
            link.setAttribute('tabindex', '-1');
        });
        document.querySelectorAll('a.nav-link[href*="new-thread"], .footer-list a[href*="new-thread"], .btn-new-thread[href*="new-thread"]').forEach(function (link) {
            link.setAttribute('href', endpoints.compose);
        });
    }

    function initLightbox() {
        var modal = document.getElementById('socialLightbox');
        if (!modal) return;
        var image = modal.querySelector('[data-lightbox-image]');
        var close = modal.querySelector('[data-lightbox-close]');
        if (!image) return;

        function closeModal() {
            modal.hidden = true;
            modal.setAttribute('aria-hidden', 'true');
            image.removeAttribute('src');
            document.body.classList.remove('social-lightbox-open');
        }

        document.querySelectorAll('[data-lightbox-src]').forEach(function (link) {
            link.addEventListener('click', function (event) {
                var src = link.getAttribute('data-lightbox-src');
                if (!src) return;
                event.preventDefault();
                image.src = src;
                image.alt = link.getAttribute('data-lightbox-alt') || '';
                modal.hidden = false;
                modal.setAttribute('aria-hidden', 'false');
                document.body.classList.add('social-lightbox-open');
            });
        });

        if (close) close.addEventListener('click', closeModal);
        modal.addEventListener('click', function (event) {
            if (event.target === modal) closeModal();
        });
        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && !modal.hidden) closeModal();
        });
    }

    function initComposer() {
        var form = document.querySelector('.composer-form');
        if (!form) return;

        document.querySelectorAll('[data-composer-panel]').forEach(function (button) {
            button.addEventListener('click', function () {
                var key = button.getAttribute('data-composer-panel');
                var panel = form.querySelector('[data-composer-panel-body="' + key + '"]');
                if (!panel) return;
                var willShow = panel.hidden;
                form.querySelectorAll('[data-composer-panel-body]').forEach(function (item) {
                    item.hidden = true;
                });
                panel.hidden = !willShow;
                form.querySelectorAll('[data-composer-panel]').forEach(function (item) {
                    item.classList.toggle('is-active', item === button && willShow);
                });
            });
        });

        var file = form.querySelector('[data-composer-file-input]');
        var chip = form.querySelector('[data-composer-file]');
        if (file && chip) {
            file.addEventListener('change', function () {
                if (file.files && file.files[0]) {
                    chip.hidden = false;
                    chip.textContent = file.files[0].name;
                } else {
                    chip.hidden = true;
                    chip.textContent = '';
                }
            });
        }

        var visibility = form.querySelector('[data-composer-visibility]');
        var icon = form.querySelector('[data-visibility-icon]');
        if (visibility && icon) {
            visibility.addEventListener('change', function () {
                icon.className = 'fas ' + (visibility.value === 'followers'
                    ? 'fa-user-group'
                    : visibility.value === 'private' ? 'fa-lock' : 'fa-globe');
            });
        }
    }

    repointMobileCompose();
    injectThreadActions();
    initLightbox();
    initComposer();
})();
