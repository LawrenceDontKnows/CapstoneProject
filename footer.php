<footer class="text-center mt-auto py-3" style="position: relative; z-index: 10;">
    <div class="container">
        <small class="text-body" style="color: var(--footer-text-color, #333);">
            <span class="d-block d-sm-inline">&copy; <?php echo date('Y'); ?></span>
            <span class="d-none d-sm-inline mx-1">|</span>
            <span class="d-block d-sm-inline">Developed by SYNTAX ERROR</span>
        </small>
    </div>
</footer>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Auto-detect effective background brightness (image + overlay) and set footer text color
    let footerColorUpdateTimer = null;
    
    function getImageBrightness(img, callback) {
        if (!img || !img.complete || img.naturalWidth === 0) {
            callback(null);
            return;
        }
        
        try {
            const canvas = document.createElement('canvas');
            const ctx = canvas.getContext('2d');
            const size = 50;
            canvas.width = size;
            canvas.height = size;
            
            const scale = Math.max(size / img.naturalWidth, size / img.naturalHeight);
            const drawWidth = img.naturalWidth * scale;
            const drawHeight = img.naturalHeight * scale;
            const offsetX = (size - drawWidth) / 2;
            const offsetY = (size - drawHeight) / 2;
            
            ctx.drawImage(img, offsetX, offsetY, drawWidth, drawHeight);
            
            const imageData = ctx.getImageData(0, 0, size, size).data;
            let r = 0, g = 0, b = 0, count = 0;
            
            for (let i = 0; i < imageData.length; i += 16) {
                r += imageData[i];
                g += imageData[i + 1];
                b += imageData[i + 2];
                count++;
            }
            
            r = Math.round(r / count);
            g = Math.round(g / count);
            b = Math.round(b / count);
            
            const luminance = (0.299 * r + 0.587 * g + 0.114 * b) / 255;
            callback({ r, g, b, luminance });
        } catch (e) {
            callback(null);
        }
    }
    
    function applyOverlay(baseColor, overlayOpacity) {
        const opacity = overlayOpacity / 100;
        return {
            r: Math.round(baseColor.r * (1 - opacity)),
            g: Math.round(baseColor.g * (1 - opacity)),
            b: Math.round(baseColor.b * (1 - opacity))
        };
    }
    
    function updateFooterTextColor() {
        const footer = document.querySelector('footer');
        if (!footer) return;
        
        // Get overlay opacity if slideshow is active
        let overlayOpacity = 0;
        const slideshowOverlay = document.querySelector('.slideshow-overlay');
        if (slideshowOverlay && slideshowOverlay.style.display !== 'none') {
            const computedStyle = getComputedStyle(slideshowOverlay);
            const bg = computedStyle.backgroundColor;
            const match = bg.match(/rgba?\((\d+),\s*(\d+),\s*(\d+)(?:,\s*([\d.]+))?\)/);
            if (match && match[4]) {
                overlayOpacity = parseFloat(match[4]) * 100;
            }
        }
        
        // Get current background image
        let bgImg = null;
        
        const activeSlide = document.querySelector('.slideshow-bg .slide.active img');
        if (activeSlide) {
            bgImg = activeSlide;
        } else {
            const registerBgImg = document.querySelector('.register-bg .bg-image img');
            if (registerBgImg && getComputedStyle(registerBgImg).display !== 'none') {
                bgImg = registerBgImg;
            }
        }
        
        if (bgImg) {
            getImageBrightness(bgImg, function(colorData) {
                if (colorData) {
                    let finalColor = colorData;
                    if (overlayOpacity > 0) {
                        finalColor = applyOverlay(colorData, overlayOpacity);
                    }
                    
                    const luminance = (0.299 * finalColor.r + 0.587 * finalColor.g + 0.114 * finalColor.b) / 255;
                    
                    const textColor = luminance > 0.5 ? '#1a1a2e' : '#ffffff';
                    const textShadow = (luminance > 0.3 && luminance < 0.7) 
                        ? (luminance > 0.5 ? '0 1px 2px rgba(0,0,0,0.3)' : '0 1px 2px rgba(255,255,255,0.3)')
                        : 'none';
                    
                    document.documentElement.style.setProperty('--footer-text-color', textColor);
                    document.documentElement.style.setProperty('--footer-text-shadow', textShadow);
                }
            });
        } else {
            const bgColor = getComputedStyle(document.body).backgroundColor;
            const rgb = bgColor.match(/\d+/g);
            if (rgb && rgb.length >= 3) {
                const r = parseInt(rgb[0]);
                const g = parseInt(rgb[1]);
                const b = parseInt(rgb[2]);
                const luminance = (0.299 * r + 0.587 * g + 0.114 * b) / 255;
                const textColor = luminance > 0.5 ? '#1a1a2e' : '#ffffff';
                const textShadow = (luminance > 0.3 && luminance < 0.7) 
                    ? (luminance > 0.5 ? '0 1px 2px rgba(0,0,0,0.3)' : '0 1px 2px rgba(255,255,255,0.3)')
                    : 'none';
                document.documentElement.style.setProperty('--footer-text-color', textColor);
                document.documentElement.style.setProperty('--footer-text-shadow', textShadow);
            }
        }
    }
    
    function scheduleUpdate() {
        if (footerColorUpdateTimer) clearTimeout(footerColorUpdateTimer);
        footerColorUpdateTimer = setTimeout(updateFooterTextColor, 50);
    }
    
    // Apply text shadow via CSS
    const style = document.createElement('style');
    style.textContent = 'footer small { text-shadow: var(--footer-text-shadow, none); transition: color 0.3s ease, text-shadow 0.3s ease; }';
    document.head.appendChild(style);
    
    updateFooterTextColor();
    
    const slideshowObserver = new MutationObserver(scheduleUpdate);
    document.querySelectorAll('.slideshow-bg .slide').forEach(slide => {
        slideshowObserver.observe(slide, { attributes: true, attributeFilter: ['class'] });
    });
    
    const overlay = document.querySelector('.slideshow-overlay');
    if (overlay) {
        slideshowObserver.observe(overlay, { attributes: true, attributeFilter: ['style', 'class'] });
    }
    
    const registerObserver = new MutationObserver(scheduleUpdate);
    const registerBg = document.querySelector('.register-bg .bg-image');
    if (registerBg) {
        registerObserver.observe(registerBg, { attributes: true, attributeFilter: ['style', 'class'] });
    }
    
    document.querySelectorAll('.slideshow-bg .slide img, .register-bg .bg-image img').forEach(img => {
        img.addEventListener('load', updateFooterTextColor);
    });
});
</script>

<!-- All Notifications Modal -->
<div class="modal fade" id="allNotificationsModal" tabindex="-1" aria-labelledby="allNotificationsModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="allNotificationsModalLabel"><i class="fas fa-bell me-2"></i> All Notifications</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="allNotificationsModalBody">
                <!-- Content will be loaded here via AJAX -->
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- Generic Alert Modal -->
<div class="modal fade" id="alertModal" tabindex="-1" aria-labelledby="alertModalLabel" aria-hidden="true" style="z-index: 1060;">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header" id="alertModalHeader">
                <h5 class="modal-title" id="alertModalLabel"><i class="fas fa-exclamation-triangle me-2"></i>Alert</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="alertModalBody">
                <!-- Message will be injected here -->
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-primary" data-bs-dismiss="modal">OK</button>
            </div>
        </div>
    </div>
</div>

<!-- Generic Confirmation Modal -->
<div class="modal fade" id="confirmModal" tabindex="-1" aria-labelledby="confirmModalLabel" aria-hidden="true" style="z-index: 1060;">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="confirmModalLabel"><i class="fas fa-question-circle me-2"></i>Please Confirm</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="confirmModalBody">
                <!-- Confirmation message will be injected here -->
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="confirmModalConfirmBtn">Confirm</button>
            </div>
        </div>
    </div>
</div>

<!-- Logout Confirmation Modal -->
<div class="modal fade" id="logoutConfirmModal" tabindex="-1" aria-labelledby="logoutConfirmLabel" aria-hidden="true" style="z-index: 1060;">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content logout-confirm-content">
            <div class="modal-header">
                <h5 class="modal-title" id="logoutConfirmLabel"><i class="fas fa-sign-out-alt me-2"></i>Logout</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="mb-0">Are you sure you want to logout?</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-logout-cancel" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-logout-confirm" id="logoutConfirmBtn"><i class="fas fa-sign-out-alt me-1"></i>Logout</button>
            </div>
        </div>
    </div>
</div>

<style>
    body.logout-modal-open .modal-backdrop.show {
        opacity: 1;
        background: rgba(10, 12, 16, 0.55);
        backdrop-filter: blur(8px) saturate(120%);
        -webkit-backdrop-filter: blur(8px) saturate(120%);
    }
    #logoutConfirmModal .logout-confirm-content {
        background: rgba(26, 28, 32, 0.72);
        backdrop-filter: blur(24px) saturate(125%);
        -webkit-backdrop-filter: blur(24px) saturate(125%);
        border: 1px solid rgba(255, 255, 255, 0.18);
        border-radius: 18px;
        box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.28), 0 30px 70px rgba(0, 0, 0, 0.65);
        color: #f5f5f4;
    }
    #logoutConfirmModal .modal-header,
    #logoutConfirmModal .modal-footer {
        border-color: rgba(255, 255, 255, 0.1);
    }
    #logoutConfirmModal .modal-title { color: #f5f5f4; font-weight: 700; }
    #logoutConfirmModal .modal-body { color: #d1d5db; }
    #logoutConfirmModal .btn-logout-cancel {
        background: rgba(255, 255, 255, 0.08);
        border: 1px solid rgba(255, 255, 255, 0.18);
        color: #f5f5f4;
    }
    #logoutConfirmModal .btn-logout-cancel:hover {
        background: rgba(255, 255, 255, 0.18);
        color: #ffffff;
    }
    #logoutConfirmModal .btn-logout-confirm {
        background: linear-gradient(135deg, #ef4444, #f97316);
        border: none;
        color: #ffffff;
        font-weight: 700;
    }
    #logoutConfirmModal .btn-logout-confirm:hover {
        filter: brightness(1.05);
        color: #ffffff;
    }
</style>

<!-- Image Cropper Modal -->
<div class="modal fade" id="cropperModal" tabindex="-1" aria-labelledby="cropperModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="cropperModalLabel">Crop Profile Picture</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="img-container" style="max-height: 500px;">
                    <img id="imageToCrop" src="" alt="Picture to crop" style="max-width: 100%;">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="cropButton">Crop & Save</button>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const cropperModalEl = document.getElementById('cropperModal');
    if (cropperModalEl) {
        const cropperModal = new bootstrap.Modal(cropperModalEl);
        const imageToCrop = document.getElementById('imageToCrop');
        const cropButton = document.getElementById('cropButton');
        let cropper;
        let currentFileInput;

        document.querySelectorAll('.cropper-file-input').forEach(input => {
            input.addEventListener('change', function(e) {
                currentFileInput = this;
                const files = e.target.files;
                if (files && files.length > 0) {
                    const reader = new FileReader();
                    reader.onload = function(event) {
                        imageToCrop.src = event.target.result;
                        cropperModal.show();
                    };
                    reader.readAsDataURL(files[0]);
                }
            });
        });

        cropperModalEl.addEventListener('shown.bs.modal', function() {
            cropper = new Cropper(imageToCrop, { aspectRatio: 1, viewMode: 1, background: false, autoCropArea: 0.8 });
        });

        cropperModalEl.addEventListener('hidden.bs.modal', function() {
            if (cropper) cropper.destroy();
            cropper = null;
            if (currentFileInput) currentFileInput.value = '';
        });

        cropButton.addEventListener('click', function() {
            if (!cropper || !currentFileInput) return;
            const canvas = cropper.getCroppedCanvas({ width: 256, height: 256, imageSmoothingQuality: 'high' });
            const croppedImageDataURL = canvas.toDataURL('image/jpeg');
            const form = currentFileInput.closest('form');
            form.querySelector('.cropper-preview').src = croppedImageDataURL;
            form.querySelector('.cropper-data').value = croppedImageDataURL;
            cropperModal.hide();
        });
    }

    // All Notifications Modal Logic
    const allNotificationsModalEl = document.getElementById('allNotificationsModal');
    if (allNotificationsModalEl) {
        const allNotificationsModal = new bootstrap.Modal(allNotificationsModalEl);
        const modalBody = document.getElementById('allNotificationsModalBody');

        document.querySelectorAll('.view-all-notifications').forEach(link => {
            link.addEventListener('click', function(e) {
                e.preventDefault();
                loadNotifications(1); // Load first page
                allNotificationsModal.show();
            });
        });

        function loadNotifications(page) {
            modalBody.innerHTML = '<div class="text-center p-5"><i class="fas fa-spinner fa-spin fa-2x text-primary"></i></div>';
            fetch(`notifications.php?ajax=1&page=${page}`)
                .then(response => response.text())
                .then(html => {
                    modalBody.innerHTML = html;
                    // Re-attach event listeners for pagination links inside the modal
                    modalBody.querySelectorAll('.pagination a').forEach(pageLink => {
                        pageLink.addEventListener('click', function(e) {
                            e.preventDefault();
                            const url = new URL(this.href);
                            const newPage = url.searchParams.get('page');
                            if (newPage) loadNotifications(newPage);
                        });
                    });
                    // Re-attach event listener for "Mark all as read" form
                    const markAllForm = modalBody.querySelector('#markAllReadForm');
                    if (markAllForm) {
                        markAllForm.addEventListener('submit', function(e) {
                            e.preventDefault();
                            fetch('notifications.php', { method: 'POST', body: new FormData(this) })
                            .then(() => {
                                loadNotifications(page); // Reload notifications to show they are read
                                const badge = document.getElementById('notif-count-badge');
                                if (badge) { badge.textContent = '0'; badge.classList.add('d-none'); }
                            });
                        });
                    }
                })
                .catch(() => modalBody.innerHTML = '<div class="alert alert-danger">Failed to load notifications.</div>');
        }
    }
});
</script>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var logoutModalEl = document.getElementById('logoutConfirmModal');
    if (!logoutModalEl || typeof bootstrap === 'undefined') return;
    var logoutModal = new bootstrap.Modal(logoutModalEl);
    var pendingLogoutEl = null;

    logoutModalEl.addEventListener('show.bs.modal', function () {
        document.body.classList.add('logout-modal-open');
    });
    logoutModalEl.addEventListener('hidden.bs.modal', function () {
        document.body.classList.remove('logout-modal-open');
    });

    document.querySelectorAll('.js-logout').forEach(function (el) {
        el.addEventListener('click', function (e) {
            e.preventDefault();
            pendingLogoutEl = el;
            logoutModal.show();
        });
    });

    var logoutConfirmBtn = document.getElementById('logoutConfirmBtn');
    if (logoutConfirmBtn) {
        logoutConfirmBtn.addEventListener('click', function () {
            var form = document.getElementById('logoutForm');
            if (form) { form.submit(); return; }
            var href = pendingLogoutEl && pendingLogoutEl.getAttribute('href');
            window.location.href = (href && href !== '#') ? href : 'logout.php';
        });
    }
});
</script>