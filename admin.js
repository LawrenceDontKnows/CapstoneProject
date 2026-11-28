(function() {
    "use strict";

    /**
     * Toggles visibility of student-specific fields in the Edit User modal.
     * @param {string} role - The selected role.
     */
    function toggleEditStudentFields(role) {
        const studentFields = document.getElementById('edit_student_fields');
        if (studentFields) {
            studentFields.style.display = (role === 'student') ? '' : 'none';
        }
    }

    /**
     * Sets the broadcast message from a quick message button.
     * @param {string} message - The message to set.
     */
    function setBroadcastMessage(messageTemplate) {        
        document.getElementById('broadcastMessage').value = messageTemplate;
    }

    // Expose necessary functions to the global scope for inline handlers
    window.toggleEditStudentFields = toggleEditStudentFields;
    window.setBroadcastMessage = setBroadcastMessage;

    // --- Generic Modal Helpers ---
    const showAlertModal = (message, title = 'Alert', type = 'warning') => {
        const modalEl = document.getElementById('alertModal');
        if (!modalEl) { alert(message); return; }
        const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
        const modalTitle = document.getElementById('alertModalLabel');
        const modalBody = document.getElementById('alertModalBody');
        const modalHeader = document.getElementById('alertModalHeader');

        const icon = type === 'danger' ? 'fa-times-circle' : (type === 'success' ? 'fa-check-circle' : 'fa-exclamation-triangle');
        modalTitle.innerHTML = `<i class="fas ${icon} me-2"></i> ${title}`;
        modalBody.innerHTML = message;
        modalHeader.className = `modal-header bg-${type} text-white`;
        
        modal.show();
    };

    const showConfirmModal = (message, onConfirm, title = 'Please Confirm') => {
        const modalEl = document.getElementById('confirmModal');
        if (!modalEl) {
            if (confirm(message)) onConfirm();
            return;
        }
        const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
        const modalTitle = document.getElementById('confirmModalLabel');
        const modalBody = document.getElementById('confirmModalBody');
        const confirmBtn = document.getElementById('confirmModalConfirmBtn');

        modalTitle.innerHTML = `<i class="fas fa-question-circle me-2"></i> ${title}`;
        modalBody.innerHTML = message;

        const newConfirmBtn = confirmBtn.cloneNode(true);
        confirmBtn.parentNode.replaceChild(newConfirmBtn, confirmBtn);

        newConfirmBtn.addEventListener('click', () => {
            onConfirm();
            modal.hide();
        }, { once: true });

        modal.show();
    };

    // --- Main Application Logic ---
    const App = {
        config: {},
        charts: {
            adminGradeChart: null,
            adminDistChart: null
        },

        _addModalListener(modalId, callback) {
            const modal = document.getElementById(modalId);
            if (modal) {
                modal.addEventListener('show.bs.modal', function(event) {
                    const button = event.relatedTarget;
                    if (button) {
                        callback.call(this, event, button); // Pass modal context 'this' and the trigger button
                    }
                });
            }
        },

        init(config) {
            this.config = config;
            this.initSidebar();
            this.initToasts();
            this.initTabs();
            this.initSearch();
            this.initModals();
            this.initValidation();
            this.handleHighlights();
            this.initPasswordToggles();

        },

        initSidebar() {
            const sidebar = document.getElementById('sidebar');
            const mainContent = document.getElementById('main-content');
            const toggle = document.getElementById('sidebarToggle');
            const overlay = document.getElementById('sidebarOverlay');

            if (!sidebar || !mainContent || !toggle || !overlay) return;

            const toggleSidebar = () => {
                sidebar.classList.toggle('collapsed');
                sidebar.classList.toggle('active');
                overlay.classList.toggle('active');
                mainContent.classList.toggle('expanded');
                window.dispatchEvent(new Event('resize')); // For charts
            };

            toggle.addEventListener('click', toggleSidebar);
            overlay.addEventListener('click', toggleSidebar);

            sidebar.querySelectorAll('.nav-link:not(.dropdown-toggle)').forEach(link => {
                link.addEventListener('click', () => {
                    if (window.innerWidth <= 992 && sidebar.classList.contains('active')) {
                        toggleSidebar();
                    }
                });
            });
        },

        initToasts() {
            const toastEl = document.getElementById('msgToast');
            if (!toastEl) return;

            const toast = new bootstrap.Toast(toastEl, { autohide: true, delay: 5000 });
            if (this.config.toastMessage) {
                document.getElementById('toastMsg').innerText = this.config.toastMessage;
                toastEl.classList.remove('bg-success', 'bg-danger', 'bg-warning', 'bg-info');
                toastEl.classList.add(`bg-${this.config.toastType}`);
                toast.show();
            }
        },

        initTabs() {
            document.querySelectorAll('a[data-bs-toggle="tab"]').forEach(tab => {
                tab.addEventListener('shown.bs.tab', () => window.dispatchEvent(new Event('resize')));
            });

            // Activate settings sub-tab from URL
            if (this.config.activeTab === 'settings' && this.config.subTab) {
                const tabEl = document.querySelector(`[data-bs-target="#pills-${this.config.subTab}"]`);
                if (tabEl) new bootstrap.Tab(tabEl).show();
            }
        },

        initSearch() {
            // Live Search
            const liveSearchInput = document.getElementById('liveSearchInput');
            const searchResultsDropdown = document.getElementById('searchResultsDropdown');
            if (liveSearchInput) {
                liveSearchInput.addEventListener('input', () => {
                    const query = liveSearchInput.value.trim();
                    if (query.length < 2) {
                        searchResultsDropdown.classList.add('d-none');
                        return;
                    }
                    fetch(`admin.php?ajax_search=${encodeURIComponent(query)}`)
                        .then(response => response.json())
                        .then(data => {
                            searchResultsDropdown.innerHTML = '';
                            if (data.length > 0) {
                                data.forEach(user => {
                                    const item = document.createElement('a');
                                    item.href = '#';
                                    item.className = 'list-group-item list-group-item-action border-bottom';
                                    item.innerHTML = `<div class="d-flex justify-content-between align-items-center"><div><h6 class="mb-0 small fw-bold">${user.first_name} ${user.last_name}</h6><small class="text-muted">@${user.username}</small></div><span class="badge bg-light text-dark border small" style="font-size: 0.6rem;">${user.role}</span></div>`;
                                    item.addEventListener('click', (e) => {
                                        e.preventDefault();
                                        liveSearchInput.value = user.username;
                                        searchResultsDropdown.classList.add('d-none');
                                        liveSearchInput.form.submit();
                                    });
                                    searchResultsDropdown.appendChild(item);
                                });
                                searchResultsDropdown.classList.remove('d-none');
                            } else {
                                searchResultsDropdown.classList.add('d-none');
                            }
                        });
                });
                document.addEventListener('click', (e) => {
                    if (!liveSearchInput.contains(e.target) && !searchResultsDropdown.contains(e.target)) {
                        searchResultsDropdown.classList.add('d-none');
                    }
                });
            }

            // Search/Clear Button Logic
            const setupSearchClearButton = (inputId, buttonId, clearUrl) => {
                const input = document.getElementById(inputId);
                const button = document.getElementById(buttonId);
                if (!input || !button) return;

                const updateButton = () => {
                    if (input.value.trim() !== '') {
                        button.type = 'button';
                        button.className = 'btn btn-secondary';
                        button.title = 'Clear Search';
                        button.innerHTML = '<i class="fas fa-times"></i>';
                        button.onclick = (e) => { e.preventDefault(); window.location.href = clearUrl; };
                    } else {
                        button.type = 'submit';
                        button.className = 'btn btn-primary';
                        button.title = 'Search';
                        button.innerHTML = '<i class="fas fa-search"></i>';
                        button.onclick = null;
                    }
                };
                input.addEventListener('input', updateButton);
                updateButton();
            };
            setupSearchClearButton('liveSearchInput', 'adminUserSearchClearBtn', 'admin.php?tab=users');
            setupSearchClearButton('archiveSearchInput', 'adminArchiveSearchClearBtn', 'admin.php?tab=archive');
        },

        initPasswordToggles() {
            document.querySelectorAll('.password-toggle').forEach(toggle => {
                toggle.addEventListener('click', function() {
                    // The input is the previous sibling element in the HTML structure
                    const input = this.previousElementSibling;
                    const icon = this.querySelector('i');

                    if (!input || !icon) return;

                    if (input.type === "password") {
                        input.type = "text";
                        icon.classList.remove('fa-eye');
                        icon.classList.add('fa-eye-slash');
                    } else {
                        input.type = "password";
                        icon.classList.remove('fa-eye-slash');
                        icon.classList.add('fa-eye');
                    }
                });
            });
        },

        initModals() {
            this._addModalListener('editUserModal', function(event, button) {
                const modal = this;
                const role = button.getAttribute('data-role');
                const profilePicture = button.getAttribute('data-profile-picture');

                modal.querySelector('#edit_user_id').value = button.getAttribute('data-id');
                modal.querySelector('#edit_username').value = button.getAttribute('data-username');
                modal.querySelector('#edit_first_name').value = button.getAttribute('data-firstname');
                modal.querySelector('#edit_last_name').value = button.getAttribute('data-lastname');
                modal.querySelector('#edit_email').value = button.getAttribute('data-email');
                modal.querySelector('#edit_role').value = role;
                modal.querySelector('#edit_course').value = button.getAttribute('data-course') || '';
                modal.querySelector('#edit_year_level').value = button.getAttribute('data-year-level') || '';
                modal.querySelector('#edit_phone_number').value = button.getAttribute('data-phone-number') || '';
                modal.querySelector('#edit_home_address').value = button.getAttribute('data-home-address') || '';
                modal.querySelector('#edit_city').value = button.getAttribute('data-city') || '';
                modal.querySelector('#edit_province').value = button.getAttribute('data-province') || '';
                modal.querySelector('#edit_guardian_name').value = button.getAttribute('data-guardian-name') || '';
                modal.querySelector('#edit_guardian_status').value = button.getAttribute('data-guardian-status') || '';
                modal.querySelector('#edit_guardian_phone').value = button.getAttribute('data-guardian-phone') || '';
                modal.querySelector('#edit_guardian_occupation').value = button.getAttribute('data-guardian-occupation') || '';
                modal.querySelector('#edit_guardian_address').value = button.getAttribute('data-guardian-address') || '';

                const previewImg = modal.querySelector('#edit_profile_picture_preview');
                previewImg.src = (profilePicture && profilePicture.trim() !== '') ? profilePicture : 'uploads/avatars/default.png';
                previewImg.onerror = function() { this.src = 'uploads/avatars/default.png'; };

                toggleEditStudentFields(role);
            });

            this._addModalListener('changePasswordModal', function(event, button) {
                const modal = this;
                modal.querySelector('#password_user_id').value = button.getAttribute('data-id');
                modal.querySelector('#password_username').value = button.getAttribute('data-username');
                
                modal.querySelector('#change_password_field').value = '';
                modal.querySelector('#confirm_password_field').value = '';
                modal.querySelector('#change-pw-strength-bar').style.width = '0%';
                modal.querySelector('#change-pw-strength-text').innerText = '';
                modal.querySelector('#change_cpass_feedback').style.display = 'none';
                modal.querySelector('#confirm_password_field').classList.remove('is-invalid');
            });

            this._addModalListener('editSubjectModal', function(event, button) {
                const modal = this;
                modal.querySelector('#edit_subject_id').value = button.getAttribute('data-id');
                modal.querySelector('#edit_subject_name').value = button.getAttribute('data-name');
                modal.querySelector('#edit_subject_code').value = button.getAttribute('data-code');
                modal.querySelector('#edit_subject_description').value = button.getAttribute('data-description');
                modal.querySelector('#edit_subject_teacher').value = button.getAttribute('data-teacher-id');
                modal.querySelector('#edit_subject_target_course').value = button.getAttribute('data-target-course');
                modal.querySelector('#edit_subject_target_year').value = button.getAttribute('data-target-year');
            });

            this._addModalListener('deleteSubjectModal', function(event, button) {
                const subjectName = button.getAttribute('data-name');
                const confirmInput = this.querySelector('#delete_confirm_input_admin');
                const confirmBtn = this.querySelector('#confirmDeleteBtnAdmin');

                this.querySelector('#delete_subject_id_admin').value = button.getAttribute('data-id');
                this.querySelector('#delete_subject_name_text_admin').innerText = subjectName;
                confirmInput.value = '';
                confirmBtn.disabled = true;

                confirmInput.addEventListener('input', () => {
                    confirmBtn.disabled = (confirmInput.value !== subjectName);
                });
            });

            this._addModalListener('editTemplateModal', function(event, button) {
                const modal = this;
                modal.querySelector('#edit_template_id').value = button.getAttribute('data-id');
                modal.querySelector('#edit_template_title').value = button.getAttribute('data-title');
                modal.querySelector('#edit_template_message').value = button.getAttribute('data-message');
                modal.querySelector('#edit_template_access').value = button.getAttribute('data-access');
            });

            const broadcastModal = document.getElementById('broadcastNotificationModal');
            if (broadcastModal) {
                const textarea = broadcastModal.querySelector('#broadcastMessage');

                const datePickerInput = broadcastModal.querySelector('#broadcast_date_picker');
                const timePickerInput = broadcastModal.querySelector('#broadcast_time_picker');
                const insertDateBtn = broadcastModal.querySelector('#insert_date_btn');
                const insertTimeBtn = broadcastModal.querySelector('#insert_time_btn');

                // Auto-insert the selected date/time into [Date]/[Time] placeholders.
                const applyDateTime = () => {
                    if (!textarea) return;
                    if (datePickerInput && datePickerInput.value) {
                        textarea.value = textarea.value.replace(/\[Date\]/g, datePickerInput.value);
                    }
                    if (timePickerInput && timePickerInput.value) {
                        textarea.value = textarea.value.replace(/\[Time\]/g, timePickerInput.value);
                    }
                };

                broadcastModal.querySelectorAll('.admin-quick-message-btn').forEach(btn => {
                    btn.addEventListener('click', function() {
                        if (textarea) textarea.value = this.dataset.message;
                        applyDateTime();
                    });
                });

                if (textarea && datePickerInput && timePickerInput && insertDateBtn && insertTimeBtn) {
                    flatpickr(datePickerInput, {
                        dateFormat: "F j, Y",
                        // theme: "airbnb", // Removed for a more compact default style
                        allowInput: true,
                        static: true, // Prevents modal from scrolling up on open
                        onChange: function(selectedDates, dateStr) {
                            if (dateStr) applyDateTime();
                        },
                        onOpen: function(selectedDates, dateStr, instance) {
                            instance.calendarContainer.addEventListener('click', e => e.stopPropagation());
                        }
                    });

                    flatpickr(timePickerInput, {
                        enableTime: true,
                        noCalendar: true,
                        dateFormat: "h:i K",
                        time_24hr: false,
                        // theme: "airbnb", // Removed for a more compact default style
                        allowInput: true,
                        static: true, // Prevents modal from scrolling up on open
                        onChange: function(selectedDates, dateStr) {
                            if (dateStr) applyDateTime();
                        },
                        onOpen: function(selectedDates, dateStr, instance) {
                            instance.calendarContainer.addEventListener('click', e => e.stopPropagation());
                        }
                    });

                    insertDateBtn.addEventListener('click', () => {
                        if (datePickerInput.value) textarea.value = textarea.value.replace(/\[Date\]/g, datePickerInput.value);
                    });

                    insertTimeBtn.addEventListener('click', () => {
                        if (timePickerInput.value) textarea.value = textarea.value.replace(/\[Time\]/g, timePickerInput.value);
                    });
                }

                // Checkbox logic for "All Users"
                const targetAllCheckbox = broadcastModal.querySelector('#targetAll');
                const targetGroupCheckboxes = broadcastModal.querySelectorAll('.target-group-checkbox');
 
                if (targetAllCheckbox && targetGroupCheckboxes.length > 0) {
                    const syncParentCheckbox = () => {
                        const numChecked = Array.from(targetGroupCheckboxes).filter(cb => cb.checked).length;
                        const total = targetGroupCheckboxes.length;

                        if (numChecked === 0) {
                            targetAllCheckbox.checked = false;
                            targetAllCheckbox.indeterminate = false;
                        } else if (numChecked === total) {
                            targetAllCheckbox.checked = true;
                            targetAllCheckbox.indeterminate = false;
                        } else {
                            targetAllCheckbox.checked = false;
                            targetAllCheckbox.indeterminate = true;
                        }
                    };

                    targetAllCheckbox.addEventListener('change', function() {
                        targetGroupCheckboxes.forEach(checkbox => { checkbox.checked = this.checked; });
                        this.indeterminate = false;
                    });
 
                    targetGroupCheckboxes.forEach(checkbox => { checkbox.addEventListener('change', syncParentCheckbox); });

                    // Add a listener to sync the parent checkbox state each time the modal is shown.
                    broadcastModal.addEventListener('show.bs.modal', function () {
                        syncParentCheckbox();
                    });
                }
            }

            const broadcastForm = document.getElementById('broadcastForm');
            if (broadcastForm) {
                broadcastForm.addEventListener('submit', function(event) {
                    event.preventDefault();

                    if (document.querySelectorAll('#broadcastNotificationModal .target-group-checkbox:checked').length === 0) {
                        showAlertModal('Please select at least one target audience before sending.', 'Validation Error', 'warning');
                        return;
                    }

                    showConfirmModal(
                        'Are you sure you want to send this broadcast to the selected groups?',
                        () => { broadcastForm.submit(); },
                        'Confirm Broadcast'
                    );
                });
            }

            const auditStartDate = document.getElementById('audit_start_date');
            const auditEndDate = document.getElementById('audit_end_date');
            if (auditStartDate && auditEndDate) {
                flatpickr(auditStartDate, {
                    dateFormat: "Y-m-d",
                    // theme: "airbnb", // Removed for a more compact default style
                    allowInput: true
                });
                flatpickr(auditEndDate, {
                    dateFormat: "Y-m-d",
                    // theme: "airbnb", // Removed for a more compact default style
                    allowInput: true
                });
            }
            this._addModalListener('viewUserProfileModal', function(event, button) {
                const modal = this;
                const data = JSON.parse(button.getAttribute('data-all'));

                // Helper function to calculate age from a birthdate string
                const calculateAge = (birthDateString) => {
                    if (!birthDateString) return 'N/A';
                    try {
                        const birthDate = new Date(birthDateString);
                        if (isNaN(birthDate.getTime())) return 'N/A'; // Invalid date
                        const today = new Date();
                        let age = today.getFullYear() - birthDate.getFullYear();
                        const m = today.getMonth() - birthDate.getMonth();
                        if (m < 0 || (m === 0 && today.getDate() < birthDate.getDate())) {
                            age--;
                        }
                        return age > 0 ? age : 'N/A';
                    } catch (e) { return 'N/A'; }
                };

                const profilePic = modal.querySelector('#view_profile_picture');
                profilePic.src = (data.profile_picture && data.profile_picture.trim() !== '') ? data.profile_picture : 'image/default_avatar.png';
                profilePic.onerror = () => { profilePic.src = 'image/default_avatar.png'; };

                // --- Left Column ---
                modal.querySelector('#view_fullname_header').innerText = `${data.first_name} ${data.last_name}`;
                modal.querySelector('#view_username_header').innerText = `@${data.username}`;
                modal.querySelector('#view_email').innerText = data.email || 'N/A';
                modal.querySelector('#view_role').innerText = data.role.toUpperCase();

                // --- Right Column (Personal Info) ---
                const birthDate = data.birth_date;
                modal.querySelector('#view_suffix').innerText = data.suffix || 'N/A';
                modal.querySelector('#view_bdate').innerText = birthDate ? new Date(birthDate).toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' }) : 'N/A';
                modal.querySelector('#view_age').innerText = calculateAge(birthDate);
                modal.querySelector('#view_sex').innerText = data.sex ? data.sex.charAt(0).toUpperCase() + data.sex.slice(1) : 'N/A';
                modal.querySelector('#view_phone').innerText = data.phone_number || 'N/A';
                modal.querySelector('#view_address').innerText = [data.home_address, data.city, data.province].filter(Boolean).join(', ') || 'N/A';

                // --- Student-Specific Sections ---
                const studentSubheader = modal.querySelector('#view_student_subheader');
                const guardianInfoSection = modal.querySelector('#view_guardian_info');

                if (data.role === 'student') {
                    // Show and populate student-specific UI elements
                    studentSubheader.style.display = 'block';
                    guardianInfoSection.style.display = 'block';
                    
                    modal.querySelector('#view_course').innerText = data.course || 'N/A';
                    modal.querySelector('#view_year').innerText = data.year_level || 'N/A';
                    
                    modal.querySelector('#view_gname').innerText = data.guardian_name || 'N/A';
                    modal.querySelector('#view_gphone').innerText = data.guardian_phone || 'N/A';
                    modal.querySelector('#view_gocc').innerText = data.guardian_occupation || 'N/A';
                    modal.querySelector('#view_gstatus').innerText = data.guardian_status || 'N/A';
                    modal.querySelector('#view_gaddress').innerText = data.guardian_address || 'N/A';
                } else {
                    // Hide student-specific UI elements for Admins/Teachers
                    studentSubheader.style.display = 'none';
                    guardianInfoSection.style.display = 'none';
                }
            });

            this._addModalListener('monitorPerformanceModal', (event, button) => this.initPerformanceCharts(event));
        },

        initValidation() {
            // Grading Policy Weights
            const gradingPolicyForm = document.getElementById('gradingPolicyForm');
            if (gradingPolicyForm) {
                gradingPolicyForm.addEventListener('submit', function(e) {
                    const getVal = (name) => parseInt(this.querySelector(`[name="${name}"]`).value || 0, 10);
                    const lec = getVal('weight_lec'), lab = getVal('weight_lab');
                    const lecQuiz = getVal('weight_lec_quiz'), lecOnline = getVal('weight_lec_online'), lecExam = getVal('weight_lec_exam');
                    const labCs = getVal('weight_lab_cs'), labAtt = getVal('weight_lab_att'), labExam = getVal('weight_lab_exam');

                    if (lec + lab !== 100) {
                        alert(`Overall weights (Lecture + Laboratory) must sum to 100%. Currently: ${lec + lab}%`);
                        e.preventDefault();
                    } else if (lecQuiz + lecOnline + lecExam !== 100) {
                        alert(`Lecture sub-components must sum to 100%. Currently: ${lecQuiz + lecOnline + lecExam}%`);
                        e.preventDefault();
                    } else if (labCs + labAtt + labExam !== 100) {
                        alert(`Laboratory sub-components must sum to 100%. Currently: ${labCs + labAtt + labExam}%`);
                        e.preventDefault();
                    }
                });
            }

            // Change Password Real-time Validation
            const changePassField = document.getElementById('change_password_field');
            const confirmPassField = document.getElementById('confirm_password_field');
            const changePasswordForm = document.getElementById('changePasswordForm');

            const validatePasswordMatch = () => {
                if (confirmPassField.value !== changePassField.value) {
                    confirmPassField.classList.add('is-invalid');
                    document.getElementById('change_cpass_feedback').style.display = 'block';
                    return false;
                } else {
                    confirmPassField.classList.remove('is-invalid');
                    document.getElementById('change_cpass_feedback').style.display = 'none';
                    return true;
                }
            };

            if (changePassField) {
                changePassField.addEventListener('input', function() {
                    const val = this.value;
                    const bar = document.getElementById('change-pw-strength-bar');
                    const text = document.getElementById('change-pw-strength-text');
                    let strength = 0;
                    if (val.length >= 8) strength++;
                    if (/[A-Z]/.test(val)) strength++;
                    if (/[0-9]/.test(val)) strength++;
                    if (/[^A-Za-z0-9]/.test(val)) strength++;
                    let color = 'bg-danger', label = 'Weak', width = '25%';
                    if (strength === 2) { color = 'bg-warning'; label = 'Fair'; width = '50%'; }
                    else if (strength === 3) { color = 'bg-info'; label = 'Good'; width = '75%'; }
                    else if (strength === 4) { color = 'bg-success'; label = 'Strong'; width = '100%'; }
                    if (bar) { bar.className = 'progress-bar ' + color; bar.style.width = val.length > 0 ? width : '0%'; }
                    if (text) text.innerText = val.length > 0 ? 'Strength: ' + label : '';
                    if (confirmPassField.value !== "") validatePasswordMatch();
                });
            }

            if (confirmPassField) confirmPassField.addEventListener('input', validatePasswordMatch);

            if (changePasswordForm) {
                changePasswordForm.addEventListener('submit', function(e) {
                    if (changePassField.value.length < 8 || !validatePasswordMatch()) {
                        e.preventDefault();
                    }
                });
            }
        },

        handleHighlights() {
            const highlightRow = (rowId) => {
                const row = document.getElementById(rowId);
                if (row) {
                    row.classList.add('row-glow-highlight');
                    row.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }
            };
            if (this.config.newSubjectId) {
                highlightRow(`subject-row-${this.config.newSubjectId}`);
            }
            if (this.config.updatedUserId) {
                highlightRow(`user-row-${this.config.updatedUserId}`);
            }
        },

        initPerformanceCharts(event) {
            const btn = event.relatedTarget;
            const studentId = btn.getAttribute('data-id');
            document.getElementById('perf_student_name').innerText = btn.getAttribute('data-name');

            const calculateGradePointJS = (grade) => {
                grade = parseFloat(grade);
                if (grade >= 96) return '1.00'; if (grade >= 91) return '1.25'; if (grade >= 86) return '1.50';
                if (grade >= 81) return '1.75'; if (grade >= 75) return '2.00'; if (grade >= 69) return '2.25';
                if (grade >= 63) return '2.50'; if (grade >= 57) return '2.75'; if (grade >= 50) return '3.00';
                if (grade > 0 && grade < 50) return '5.00';
                return 'INC';
            };

            fetch(`admin.php?ajax_performance=${studentId}`)
                .then(res => res.json())
                .then(data => {
                    document.getElementById('perf_total').innerText = data.total;

                    if (this.charts.adminGradeChart) this.charts.adminGradeChart.destroy();
                    if (this.charts.adminDistChart) this.charts.adminDistChart.destroy();

                    this.charts.adminGradeChart = new Chart(document.getElementById('adminGradeChart').getContext('2d'), {
                        type: 'line',
                        data: { labels: ['Prelim', 'Midterm', 'Prefinal', 'Final'], datasets: data.datasets },
                        options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { y: { min: 0, max: 100 } } }
                    });

                    this.charts.adminDistChart = new Chart(document.getElementById('adminDistChart').getContext('2d'), {
                        type: 'pie',
                        data: {
                            labels: Object.keys(data.distribution),
                            datasets: [{
                                data: Object.values(data.distribution),
                                backgroundColor: ['#10b981', '#10b981', '#2563eb', '#2563eb', '#3b82f6', '#3b82f6', '#f59e0b', '#f59e0b', '#ef4444', '#1e293b', '#64748b']
                            }]
                        },
                        options: { responsive: true, maintainAspectRatio: false }
                    });

                    const legendContainer = document.getElementById('adminGradeChartLegend');
                    if (legendContainer) {
                        legendContainer.innerHTML = '';
                        this.charts.adminGradeChart.data.datasets.forEach((dataset, index) => {
                            const div = document.createElement('div');
                            div.className = 'form-check form-check-inline';
                            div.innerHTML = `<input class="form-check-input" type="checkbox" id="a_ds_check_${index}" checked style="cursor: pointer; transition: all 0.3s ease;"><label class="form-check-label small fw-bold d-flex align-items-center" for="a_ds_check_${index}" style="cursor: pointer;"><span class="me-2" style="width: 12px; height: 12px; background-color: ${dataset.borderColor}; display: inline-block; border-radius: 2px;"></span>${dataset.label}</label>`;
                            div.querySelector('input').addEventListener('change', (e) => {
                                div.querySelector('label').style.opacity = e.target.checked ? '1' : '0.4';
                                this.charts.adminGradeChart.setDatasetVisibility(index, e.target.checked);
                                this.charts.adminGradeChart.update();
                                updateModalPeriodicStats();
                            });
                            legendContainer.appendChild(div);
                        });
                    }

                    const updateModalPeriodicStats = () => {
                        if (!this.charts.adminGradeChart) return;
                        let sums = [0, 0, 0, 0], counts = [0, 0, 0, 0], totalSum = 0, totalCount = 0;
                        this.charts.adminGradeChart.data.datasets.forEach((ds, i) => {
                            if (this.charts.adminGradeChart.isDatasetVisible(i)) {
                                let subVal = 0, subPeriods = 0;
                                ds.data.forEach((val, idx) => {
                                    if (val > 0) { sums[idx] += val; counts[idx]++; subVal += val; subPeriods++; }
                                });
                                if (subPeriods > 0) { totalSum += (subVal / subPeriods); totalCount++; }
                            }
                        });
                        const overallAvg = totalCount > 0 ? totalSum / totalCount : 0;
                        document.getElementById('perf_avg').innerText = overallAvg.toFixed(1);
                        document.getElementById('perf_letter').innerText = calculateGradePointJS(overallAvg);
                        const standing = overallAvg >= 75 ? 'Passing' : (totalCount > 0 ? 'Failing' : 'No Data');
                        document.getElementById('perf_standing').innerText = standing;
                        const statusText = document.getElementById('perf_status_text');
                        statusText.className = 'mt-2 fw-bold ' + (standing === 'Passing' ? 'text-success' : 'text-danger');
                        statusText.innerText = standing === 'Passing' ? 'Academic criteria met' : 'Improvement recommended';
                        ['perf_prelim', 'perf_midterm', 'perf_prefinal', 'perf_final'].forEach((id, idx) => {
                            const avg = counts[idx] > 0 ? (sums[idx] / counts[idx]) : 0;
                            document.getElementById(id).innerText = avg > 0 ? `${avg.toFixed(1)} (${calculateGradePointJS(avg)})` : '0.0';
                        });
                    };
                    updateModalPeriodicStats();
                });
        }
    };

    document.addEventListener('DOMContentLoaded', () => {
        // window.pageConfig is set in a script tag in admin.php
        App.init(window.pageConfig || {});
    });

})();