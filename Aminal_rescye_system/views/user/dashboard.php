<?php
/** @var array<string, mixed>|null $user_data */
/** @var array<int, array<string, mixed>> $reports */
/** @var array{total: int, active: int, completed: int} $stats */
/** @var array<int, array<string, mixed>> $activity */
/** @var array<int, array<string, mixed>> $notifications */
/** @var int $unread_notifications */
/** @var string $reports_json */
/** @var array{message: string, type: string} $flash */

$ud = $user_data ?? [];
$name = htmlspecialchars((string) ($ud['name'] ?? 'User'), ENT_QUOTES, 'UTF-8');
?>

<script>
window.__USER_DASHBOARD_INITIAL__ = <?php echo $reports_json; ?>;
window.__USER_POLL_URL__ = 'backend/api/user_dashboard_poll.php';
window.__USER_FLASH__ = <?php echo json_encode($flash ?? ['message' => '', 'type' => ''], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
</script>

<a href="#ud-main" class="skip-link visually-hidden">Skip to main content</a>

<div class="ud-shell">
    <aside class="ud-sidebar glass-panel" id="ud-sidebar-drawer" aria-label="Dashboard sections">
        <div class="ud-profile">
            <?php if (!empty($ud['profile_picture'])): ?>
                <img src="<?php echo htmlspecialchars((string) $ud['profile_picture'], ENT_QUOTES, 'UTF-8'); ?>" alt="" class="ud-avatar" width="72" height="72">
            <?php else: ?>
                <div class="ud-avatar ud-avatar--placeholder" aria-hidden="true"><i class="fa-solid fa-user"></i></div>
            <?php endif; ?>
            <div>
                <p class="ud-profile__name"><?php echo $name; ?></p>
                <p class="ud-profile__role">Reporter</p>
            </div>
        </div>

        <nav class="ud-nav" aria-label="Dashboard">
            <button type="button" class="ud-nav__link is-active" data-ud-panel="overview" aria-current="page">
                <i class="fa-solid fa-chart-pie" aria-hidden="true"></i><span>Overview</span>
            </button>
            <button type="button" class="ud-nav__link" data-ud-panel="notifications">
                <i class="fa-solid fa-bell" aria-hidden="true"></i><span>Notifications</span>
                <span class="ud-nav__count" style="<?php echo !empty($unread_notifications) ? '' : 'display:none;'; ?>"><?php echo (int) $unread_notifications; ?></span>
            </button>
            <button type="button" class="ud-nav__link" data-ud-panel="report">
                <i class="fa-solid fa-circle-plus" aria-hidden="true"></i><span>New rescue report</span>
            </button>
            <button type="button" class="ud-nav__link" data-ud-panel="cases">
                <i class="fa-solid fa-route" aria-hidden="true"></i><span>Track requests</span>
            </button>
            <button type="button" class="ud-nav__link" data-ud-panel="profile">
                <i class="fa-solid fa-user-gear" aria-hidden="true"></i><span>Profile</span>
            </button>
        </nav>

        <div class="ud-sidebar__footer">
            <button type="button" class="ud-theme-toggle" id="ud-theme-toggle" aria-pressed="false" title="Toggle dark mode">
                <i class="fa-solid fa-moon" aria-hidden="true"></i><span class="ud-theme-toggle__text">Dark mode</span>
            </button>
            <a href="logout.php" class="ud-logout"><i class="fa-solid fa-right-from-bracket" aria-hidden="true"></i> Logout</a>
        </div>
    </aside>

    <div class="ud-main-wrap">
        <header class="ud-topbar glass-panel">
            <button type="button" class="ud-mobile-menu" id="ud-mobile-menu" aria-expanded="false" aria-controls="ud-sidebar-drawer" aria-label="Open menu">
                <i class="fa-solid fa-bars" aria-hidden="true"></i>
            </button>
            <div>
                <h1 class="ud-title" id="ud-main">Your dashboard</h1>
                <p class="ud-subtitle">Report animals in need, follow live status, and stay informed.</p>
            </div>
            <div class="ud-topbar__actions" aria-live="polite" aria-atomic="true" id="ud-notify-live" role="status"></div>
        </header>

        <main class="ud-panels">
            <section id="ud-panel-overview" class="ud-panel is-visible" aria-labelledby="ud-overview-heading">
                <h2 id="ud-overview-heading" class="visually-hidden">Overview</h2>
                <div class="ud-stats" role="region" aria-label="Your statistics">
                    <article class="ud-stat ud-stat--anim">
                        <span class="ud-stat__icon" aria-hidden="true"><i class="fa-solid fa-file-medical"></i></span>
                        <p class="ud-stat__value"><?php echo (int) ($stats['total'] ?? 0); ?></p>
                        <p class="ud-stat__label">Total reports</p>
                    </article>
                    <article class="ud-stat ud-stat--anim">
                        <span class="ud-stat__icon ud-stat__icon--amber" aria-hidden="true"><i class="fa-solid fa-bolt"></i></span>
                        <p class="ud-stat__value"><?php echo (int) ($stats['active'] ?? 0); ?></p>
                        <p class="ud-stat__label">Active cases</p>
                    </article>
                    <article class="ud-stat ud-stat--anim">
                        <span class="ud-stat__icon ud-stat__icon--green" aria-hidden="true"><i class="fa-solid fa-circle-check"></i></span>
                        <p class="ud-stat__value"><?php echo (int) ($stats['completed'] ?? 0); ?></p>
                        <p class="ud-stat__label">Completed rescues</p>
                    </article>
                </div>

                <div class="ud-grid-2">
                    <div class="ud-card glass-panel ud-card--pad">
                        <h3 class="ud-card__title"><i class="fa-solid fa-clock-rotate-left" aria-hidden="true"></i> Recent activity</h3>
                        <ul class="ud-feed" id="ud-activity-feed">
                            <?php if (empty($activity)): ?>
                                <li class="ud-feed__empty">No activity yet. Submit your first report to see updates here.</li>
                            <?php else: ?>
                                <?php foreach ($activity as $item): ?>
                                    <li class="ud-feed__item">
                                        <span class="ud-feed__dot" aria-hidden="true"></span>
                                        <div>
                                            <p class="ud-feed__msg"><?php echo htmlspecialchars((string) $item['message'], ENT_QUOTES, 'UTF-8'); ?></p>
                                            <time class="ud-feed__time" datetime="<?php echo htmlspecialchars((string) $item['time'], ENT_QUOTES, 'UTF-8'); ?>">
                                                <?php echo date('M j, Y g:i A', strtotime((string) $item['time'])); ?>
                                            </time>
                                        </div>
                                    </li>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </ul>
                    </div>
                    <div class="ud-card glass-panel ud-card--pad ud-quick">
                        <h3 class="ud-card__title"><i class="fa-solid fa-shield-heart" aria-hidden="true"></i> Quick actions</h3>
                        <p class="ud-quick__text">Photos are checked for an animal before dispatch. Use a clear picture and accurate map pin for the fastest response.</p>
                        <button type="button" class="btn btn-primary ud-quick__btn" data-ud-goto="report">Start a rescue report</button>
                    </div>
                </div>
            </section>

            <section id="ud-panel-report" class="ud-panel" hidden aria-labelledby="ud-report-heading">
                <h2 id="ud-report-heading" class="ud-h2"><i class="fa-solid fa-location-dot" aria-hidden="true"></i> Submit a rescue request</h2>
                <p class="ud-lead">Upload a photo, describe the situation, set priority, and place the pin on the map (or use your GPS).</p>

                <form method="post" action="" enctype="multipart/form-data" class="ud-form glass-panel ud-card--pad" id="ud-rescue-form">
                    <input type="hidden" name="latitude" id="ud-lat" value="" required>
                    <input type="hidden" name="longitude" id="ud-lon" value="" required>

                    <div class="ud-form__grid">
                        <div class="form-group">
                            <label for="ud-animal-type">Animal type</label>
                            <input
                                type="text"
                                name="animal_type"
                                id="ud-animal-type"
                                class="form-control"
                                required
                                maxlength="60"
                                pattern="[A-Za-z0-9\s\-',\.]{2,60}"
                                placeholder="e.g. injured puppy, cat, cow, bird"
                                title="Use 2-60 characters: letters, numbers, spaces, hyphen, comma, apostrophe, dot"
                            >
                        </div>
                        <div class="form-group">
                            <label for="ud-priority">Report priority</label>
                            <select name="report_priority" id="ud-priority" class="form-control" aria-describedby="ud-priority-hint">
                                <option value="normal">Normal</option>
                                <option value="medium">Medium</option>
                                <option value="critical">Critical</option>
                            </select>
                            <p id="ud-priority-hint" class="ud-hint">
                                <span class="prio-swatch prio-swatch--normal" title="Normal"></span> Normal
                                <span class="prio-swatch prio-swatch--medium" title="Medium"></span> Medium
                                <span class="prio-swatch prio-swatch--critical" title="Critical"></span> Critical
                            </p>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="ud-description">Description</label>
                        <textarea name="description" id="ud-description" class="form-control" rows="4" required placeholder="Condition, behavior, nearby landmarks, hazards…"></textarea>
                    </div>

                    <div class="form-group">
                        <label for="ud-image">Photo (required for AI validation)</label>
                        <input type="file" name="image" id="ud-image" class="form-control ud-file-input" accept="image/jpeg,image/png,image/webp" required>
                        <div class="ud-photo-actions">
                            <button type="button" class="btn btn-secondary ud-btn-camera" id="ud-open-camera" aria-label="Open camera to take a photo">
                                <i class="fa-solid fa-camera" aria-hidden="true"></i> Take photo
                            </button>
                            <span class="ud-hint ud-photo-actions__hint">On a phone, you can also use “Choose file” and pick Camera.</span>
                        </div>
                        <input type="file" id="ud-camera-only" class="visually-hidden" accept="image/*" capture="environment" tabindex="-1" aria-hidden="true">
                        <p class="ud-hint">The system checks that an animal is visible before injury-based dispatch rules apply.</p>
                        <img src="" alt="Preview of selected photo" id="ud-image-preview" class="ud-preview" width="600" height="320" hidden>
                    </div>

                    <div class="form-group">
                        <label for="ud-location-text">Location notes (optional)</label>
                        <input type="text" name="location_text" id="ud-location-text" class="form-control" placeholder="e.g. Near City Mall east gate">
                    </div>

                    <div class="form-group">
                        <span class="label-like" id="ud-map-label">Map — tap to set pin, search, or switch layers</span>
                        <div class="ud-map-toolbar glass-panel">
                            <label class="visually-hidden" for="ud-map-search-q">Search place or address</label>
                            <input type="search" id="ud-map-search-q" class="form-control ud-map-search-input" placeholder="Search place or address…" autocomplete="off">
                            <button type="button" class="btn btn-secondary" id="ud-map-search-btn"><i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i> Find</button>
                        </div>
                        <div id="ud-map" class="ud-map" role="application" aria-labelledby="ud-map-label"></div>
                        <div class="ud-map-actions">
                            <button type="button" class="btn btn-secondary" id="ud-use-location"><i class="fa-solid fa-crosshairs" aria-hidden="true"></i> Use my location</button>
                            <span class="ud-coords" id="ud-coords-hint" aria-live="polite">No coordinates yet — pick a point or use GPS.</span>
                        </div>
                    </div>

                    <button type="submit" name="report" value="1" class="btn btn-primary ud-submit" id="ud-submit-report">
                        <i class="fa-solid fa-paper-plane" aria-hidden="true"></i> Submit report
                    </button>
                </form>
            </section>

            <section id="ud-panel-notifications" class="ud-panel" hidden aria-labelledby="ud-notifications-heading">
                <h2 id="ud-notifications-heading" class="ud-h2"><i class="fa-solid fa-bell" aria-hidden="true"></i> Notifications</h2>
                <p class="ud-lead">Live updates from rescuers and dispatch about your reported animals.</p>

                <div class="ud-card glass-panel ud-card--pad">
                    <div class="ud-notify-head">
                        <p class="ud-notify-head__meta"><strong id="ud-notify-count"><?php echo (int) ($unread_notifications ?? 0); ?></strong> unread updates</p>
                        <button type="button" class="btn btn-secondary" id="ud-mark-read-btn">
                            <i class="fa-solid fa-check-double" aria-hidden="true"></i> Mark all as read
                        </button>
                    </div>

                    <ul class="ud-notifications" id="ud-notifications-list">
                        <?php if (empty($notifications)): ?>
                            <li class="ud-notifications__empty">No notifications yet. Updates will appear when rescuers act on your case.</li>
                        <?php else: ?>
                            <?php foreach ($notifications as $n): ?>
                                <li class="ud-notification-item <?php echo !empty($n['is_read']) ? '' : 'is-unread'; ?>">
                                    <span class="ud-notification-item__dot" aria-hidden="true"></span>
                                    <div>
                                        <p class="ud-notification-item__msg"><?php echo htmlspecialchars((string) $n['message'], ENT_QUOTES, 'UTF-8'); ?></p>
                                        <time class="ud-notification-item__time" datetime="<?php echo htmlspecialchars((string) $n['created_at'], ENT_QUOTES, 'UTF-8'); ?>">
                                            <?php echo date('M j, Y g:i A', strtotime((string) $n['created_at'])); ?>
                                        </time>
                                    </div>
                                </li>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </ul>
                </div>
            </section>

            <section id="ud-panel-cases" class="ud-panel" hidden aria-labelledby="ud-cases-heading">
                <h2 id="ud-cases-heading" class="ud-h2"><i class="fa-solid fa-list-check" aria-hidden="true"></i> Request tracking</h2>
                <p class="ud-lead">Statuses update automatically. Pending → Rescuer assigned → Accepted → Completed.</p>

                <div class="ud-cases" id="ud-cases-list">
                    <?php if (empty($reports)): ?>
                        <p class="ud-empty">You have no reports yet.</p>
                    <?php else: ?>
                        <?php foreach ($reports as $report): ?>
                            <?php
                            $track = UserCaseTracking::fromRow($report);
                            $badge = UserCaseTracking::priorityBadge($report);
                            $rid = (int) $report['id'];
                            ?>
                            <article class="ud-case glass-panel" data-case-id="<?php echo $rid; ?>">
                                <header class="ud-case__head">
                                    <div>
                                        <h3 class="ud-case__title"><?php echo htmlspecialchars(ucfirst((string) $report['animal_type']), ENT_QUOTES, 'UTF-8'); ?></h3>
                                        <time class="ud-case__time" datetime="<?php echo htmlspecialchars((string) $report['created_at'], ENT_QUOTES, 'UTF-8'); ?>">
                                            <?php echo date('M j, Y g:i A', strtotime((string) $report['created_at'])); ?>
                                        </time>
                                    </div>
                                    <span class="ud-prio-badge <?php echo htmlspecialchars($badge['class'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($badge['label'], ENT_QUOTES, 'UTF-8'); ?></span>
                                </header>
                                <p class="ud-case__desc"><?php echo htmlspecialchars((string) $report['description'], ENT_QUOTES, 'UTF-8'); ?></p>

                                <?php if ($track['variant'] === 'rejected'): ?>
                                    <div class="ud-timeline ud-timeline--rejected" role="group" aria-label="Request outcome">
                                        <p class="ud-reject-msg"><i class="fa-solid fa-circle-xmark" aria-hidden="true"></i> This request was not dispatched (image validation or AI rules).</p>
                                    </div>
                                <?php else: ?>
                                    <div class="ud-progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?php echo (int) $track['percent']; ?>" aria-label="Case progress">
                                        <div class="ud-progress__fill" style="width: <?php echo (int) $track['percent']; ?>%;"></div>
                                    </div>
                                    <ol class="ud-timeline">
                                        <?php foreach ($track['steps'] as $i => $step): ?>
                                            <li class="ud-timeline__step <?php echo !empty($step['done']) ? 'is-done' : ''; ?> <?php echo !empty($step['active']) ? 'is-active' : ''; ?>">
                                                <span class="ud-timeline__dot" aria-hidden="true"></span>
                                                <span class="ud-timeline__label"><?php echo htmlspecialchars((string) $step['label'], ENT_QUOTES, 'UTF-8'); ?></span>
                                            </li>
                                        <?php endforeach; ?>
                                    </ol>
                                <?php endif; ?>
                            </article>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </section>

            <section id="ud-panel-profile" class="ud-panel" hidden aria-labelledby="ud-profile-heading">
                <h2 id="ud-profile-heading" class="ud-h2"><i class="fa-solid fa-user-gear" aria-hidden="true"></i> Profile</h2>
                <form method="post" action="" enctype="multipart/form-data" class="ud-form glass-panel ud-card--pad">
                    <input type="hidden" name="update_profile" value="1">
                    <div class="form-group">
                        <label for="ud-profile-pic">Profile picture</label>
                        <input type="file" name="profile_picture" id="ud-profile-pic" class="form-control" accept="image/*">
                    </div>
                    <div class="form-group">
                        <label for="ud-new-password">New password</label>
                        <input type="password" name="new_password" id="ud-new-password" class="form-control" autocomplete="new-password" placeholder="Leave blank to keep current" minlength="6">
                    </div>
                    <div class="form-group">
                        <label for="ud-confirm-password">Confirm password</label>
                        <input type="password" name="confirm_password" id="ud-confirm-password" class="form-control" autocomplete="new-password" placeholder="Re-enter new password">
                        <small id="ud-pw-match" style="font-size:0.78rem;"></small>
                    </div>
                    <button type="submit" class="btn btn-primary"><i class="fa-solid fa-floppy-disk" aria-hidden="true"></i> Save changes</button>
                </form>
                <script>
                (function(){
                    var pw = document.getElementById('ud-new-password');
                    var cpw = document.getElementById('ud-confirm-password');
                    var hint = document.getElementById('ud-pw-match');
                    function checkMatch(){
                        if(!pw.value && !cpw.value){ hint.textContent=''; return; }
                        if(!pw.value || !cpw.value){ hint.textContent=''; return; }
                        if(pw.value === cpw.value){ hint.textContent='\u2713 Passwords match'; hint.style.color='#16a34a'; }
                        else{ hint.textContent='\u2717 Passwords do not match'; hint.style.color='#dc2626'; }
                    }
                    pw.addEventListener('input', checkMatch);
                    cpw.addEventListener('input', checkMatch);
                })();
                </script>
            </section>
        </main>
    </div>

    <aside class="ud-toast-host" id="ud-toast-host" aria-live="polite" aria-relevant="additions"></aside>
</div>

<div class="ud-drawer-overlay" id="ud-drawer-overlay" hidden></div>
