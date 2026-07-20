(function ($) {
    'use strict';

    const ShopBuilder = {

        init: function () {
            this.initProductSearch();
            this.initCategorySearch();
            this.bindEvents();
            this.initHeroImageUpload();
            this.initLogoUpload();
            this.initAppIconUpload();
            this.bindFormSubmit();
            this.bindGithubPush();
            this.initSections();
            this.bindGenerateIcons();
        },

        /* -------------------------
        Generic single-image upload (wp.media) — used for hero image,
        header logo, and app icon. Keeps one implementation instead of
        three near-identical copies.
        ------------------------- */
        bindMediaUpload: function ( opts ) {
            // opts: { uploadBtn, removeBtn, hiddenInput, previewImg, title, changeLabel, selectLabel }
            let uploader;
            const $uploadBtn = $(opts.uploadBtn);
            const $removeBtn = $(opts.removeBtn);
            const $hidden = $(opts.hiddenInput);
            const $preview = $(opts.previewImg);

            $uploadBtn.on('click', function (e) {
                e.preventDefault();

                if (uploader) {
                    uploader.open();
                    return;
                }

                uploader = wp.media({
                    title: opts.title,
                    button: { text: 'Use this image' },
                    multiple: false,
                    library: { type: 'image' }
                });

                uploader.on('select', function () {
                    const attachment = uploader.state().get('selection').first().toJSON();
                    $hidden.val(attachment.id);
                    $preview.attr('src', attachment.url).show();
                    $uploadBtn.text(opts.changeLabel);
                    $removeBtn.show();
                });

                uploader.open();
            });

            $removeBtn.on('click', function (e) {
                e.preventDefault();
                $hidden.val('');
                $preview.hide();
                $uploadBtn.text(opts.selectLabel);
                $(this).hide();
            });
        },

        initHeroImageUpload: function () {
            this.bindMediaUpload({
                uploadBtn: '#hero-image-upload-btn',
                removeBtn: '#hero-image-remove-btn',
                hiddenInput: '#hero-image-id',
                previewImg: '#hero-image-preview',
                title: 'Select Hero Image',
                changeLabel: 'Change Image',
                selectLabel: 'Select Image'
            });
        },

        initLogoUpload: function () {
            this.bindMediaUpload({
                uploadBtn: '#logo-upload-btn',
                removeBtn: '#logo-remove-btn',
                hiddenInput: '#logo-id',
                previewImg: '#logo-preview',
                title: 'Select Header Logo',
                changeLabel: 'Change Logo',
                selectLabel: 'Select Logo'
            });
        },

        initAppIconUpload: function () {
            const self = this;

            $('#app-icon-upload-btn').on('click', function (e) {
                e.preventDefault();

                const uploader = wp.media({
                    title: 'Select App Icon (square, 512x512 or larger)',
                    button: { text: 'Use this image' },
                    multiple: false,
                    library: { type: 'image' }
                });

                uploader.on('select', function () {
                    const attachment = uploader.state().get('selection').first().toJSON();
                    const w = attachment.width || 0;
                    const h = attachment.height || 0;

                    let warning = '';
                    if (w && h) {
                        const ratio = w / h;
                        if (ratio < 0.9 || ratio > 1.1) {
                            warning = 'Heads up: this image isn\'t square, it will be center-cropped.';
                        } else if (w < 512 || h < 512) {
                            warning = 'Heads up: image is smaller than the recommended 512x512 minimum.';
                        }
                    }

                    $('#app-icon-id').val(attachment.id);
                    $('#app-icon-preview').attr('src', attachment.url).show();
                    $('#app-icon-upload-btn').text('Change App Icon');
                    $('#app-icon-remove-btn').show();

                    if (warning) {
                        ShopBuilder.showStatus('⚠️ ' + warning, '#b45309', 6000, '#icon-gen-status');
                    }
                });

                uploader.open();
            });

            $('#app-icon-remove-btn').on('click', function (e) {
                e.preventDefault();
                $('#app-icon-id').val('');
                $('#app-icon-preview').hide();
                $('#app-icon-upload-btn').text('Select App Icon');
                $(this).hide();
            });
        },

        bindGenerateIcons: function () {
            $('#generate-icons-btn').on('click', function (e) {
                e.preventDefault();

                if (!$('#app-icon-id').val()) {
                    ShopBuilder.showStatus('❌ Select and save an App Icon first.', 'red', 5000, '#icon-gen-status');
                    return;
                }

                const $btn = $(this);
                $btn.prop('disabled', true).text('Generating...');
                ShopBuilder.showStatus('⏳ Generating icon set...', '#666', false, '#icon-gen-status');

                $.post(ajaxurl, {
                    action: 'shop_builder_generate_icons',
                    nonce: shopBuilder.nonce
                }, function (response) {
                    if (response.success) {
                        ShopBuilder.showStatus('✅ ' + response.data, 'green', 8000, '#icon-gen-status');
                    } else {
                        ShopBuilder.showStatus('❌ ' + (response.data || 'Unknown error'), 'red', 8000, '#icon-gen-status');
                    }
                }).fail(function () {
                    ShopBuilder.showStatus('❌ Connection error. Please try again.', 'red', 8000, '#icon-gen-status');
                }).always(function () {
                    $btn.prop('disabled', false).text('Generate & Push Icon Set to GitHub');
                });
            });
        },

        /* -------------------------
           Product Search (Select2)
           ------------------------- */
        initProductSearch: function () {
            const $select = $('#hp-product-select');
            const $wrapper = $select.closest('.custom-select-wrapper');

            if (!$select.length || !$.fn.select2) return;

            $select.select2({
                allowClear: true,
                closeOnSelect: false,
                placeholder: $select.data('placeholder'),
                dropdownParent: $wrapper,
                ajax: {
                    url: ajaxurl,
                    dataType: 'json',
                    delay: 250,
                    data: function (params) {
                        return {
                            action: 'shop_builder_product_search',
                            term: params.term,
                            security: shopBuilder.nonce
                        };
                    },
                    processResults: function (data) {
                        return {
                            results: data.map(function (item) {
                                return { id: item.id, text: item.text, thumb: item.thumb };
                            })
                        };
                    },
                    cache: true
                },
                templateResult: function (item) {
                    if (!item.id) return item.text;
                    const img = item.thumb || 'https://via.placeholder.com/40';
                    return $(`
                        <div style="display:flex; align-items:center; gap:10px;">
                            <img src="${img}" style="width:40px; height:40px; object-fit:cover; border-radius:4px;" />
                            <span>${item.text}</span>
                        </div>
                    `);
                },
                templateSelection: function (item) { return item.text; },
                escapeMarkup: function (markup) { return markup; }
            });

            this.bindSelect2OpenBehavior($select);
        },

        /* -------------------------
           Category Search (Select2) — used by the Category Grid section.
           Delegated on the container since section rows are added dynamically.
           ------------------------- */
        initCategorySearch: function () {
            const self = this;

            $(document).on('select2-init-category', '.category-select', function () {
                const $select = $(this);
                if ($select.data('select2')) return; // already initialized
                const $wrapper = $select.closest('.custom-select-wrapper');

                $select.select2({
                    allowClear: true,
                    closeOnSelect: false,
                    placeholder: $select.data('placeholder'),
                    dropdownParent: $wrapper,
                    ajax: {
                        url: ajaxurl,
                        dataType: 'json',
                        delay: 250,
                        data: function (params) {
                            return {
                                action: 'shop_builder_category_search',
                                term: params.term,
                                security: shopBuilder.nonce
                            };
                        },
                        processResults: function (data) {
                            return {
                                results: data.map(function (item) {
                                    return { id: item.id, text: item.text, thumb: item.thumb };
                                })
                            };
                        },
                        cache: true
                    },
                    templateResult: function (item) {
                        if (!item.id) return item.text;
                        const img = item.thumb || 'https://via.placeholder.com/40';
                        return $(`
                            <div style="display:flex; align-items:center; gap:10px;">
                                <img src="${img}" style="width:40px; height:40px; object-fit:cover; border-radius:4px;" />
                                <span>${item.text}</span>
                            </div>
                        `);
                    },
                    templateSelection: function (item) { return item.text; },
                    escapeMarkup: function (markup) { return markup; }
                });

                self.bindSelect2OpenBehavior($select);
            });

            // Initialize any category selects already present on page load.
            $('.category-select').each(function () {
                $(this).trigger('select2-init-category');
            });
        },

        bindSelect2OpenBehavior: function ($select) {
            $(document).on('keyup', '.select2-search__field', function () {
                const term = $(this).val();
                if (term.length > 0) {
                    $select.select2('open');
                    $(this).focus();
                }
            });

            $select
                .on('select2:open', function () {
                    const $searchField = $('.select2 .select2-search__field');
                    if ($searchField.length > 0 && !$searchField.val()) {
                        $(this).select2('close');
                    }
                })
                .on('select2:unselect', function (e) {
                    const idToRemove = e.params.data.id;
                    $(this).find('option[value="' + idToRemove + '"]').remove();
                    $(this).trigger('change');
                    const self = $(this);
                    setTimeout(function () { self.select2('close'); }, 1);
                })
                .on('select2:clearing', function () {
                    $(this).empty().trigger('change');
                });
        },

        /* -------------------------
           Homepage Sections: add / remove / reorder / reindex / collapse
           ------------------------- */
        initSections: function () {
            const self = this;
            const $container = $('#sections-container');

            // Drag to reorder.
            if ($.fn.sortable) {
                $container.sortable({
                    handle: '.section-drag-handle',
                    axis: 'y',
                    placeholder: 'section-row-placeholder',
                    update: function () { self.reindexSections(); }
                });
            }

            $('#add-section-btn').on('click', function (e) {
                e.preventDefault();
                const type = $('#add-section-type').val();
                self.addSection(type);
            });

            $container.on('click', '.remove-section-btn', function (e) {
                e.preventDefault();
                if (!confirm('Remove this section?')) return;
                $(this).closest('.section-row').remove();
                self.reindexSections();
            });

            $container.on('click', '.add-testimonial-btn', function (e) {
                e.preventDefault();
                const $section = $(this).closest('.section-row');
                self.addTestimonialItem($section);
            });

            $container.on('click', '.remove-testimonial-btn', function (e) {
                e.preventDefault();
                $(this).closest('.testimonial-item').remove();
                self.reindexSections(); // testimonial item indices live inside section data too
            });

            // Per-section collapse/expand toggle.
            $container.on('click', '.section-toggle-btn', function (e) {
                e.preventDefault();
                self.toggleSection($(this).closest('.section-row'));
            });

            // Collapse All / Expand All.
            $('#collapse-all-sections').on('click', function (e) {
                e.preventDefault();
                $('#sections-container > .section-row').each(function () {
                    self.setSectionCollapsed($(this), true);
                });
            });

            $('#expand-all-sections').on('click', function (e) {
                e.preventDefault();
                $('#sections-container > .section-row').each(function () {
                    self.setSectionCollapsed($(this), false);
                });
            });
        },

        /**
         * Collapsing/expanding is purely a UI convenience for making
         * drag-reordering easier on pages with many sections — it never
         * touches form field names/values, so it has no effect on what
         * gets submitted or saved.
         */
        toggleSection: function ($row) {
            const isCollapsed = $row.hasClass('section-collapsed');
            this.setSectionCollapsed($row, !isCollapsed);
        },

        setSectionCollapsed: function ($row, collapsed) {
            const $body = $row.find('.section-row-body');
            const $btn = $row.find('.section-toggle-btn');
            const $icon = $btn.find('.dashicons');

            if (collapsed) {
                $body.slideUp(150);
                $row.addClass('section-collapsed');
                $btn.attr('aria-expanded', 'false');
                $icon.removeClass('dashicons-arrow-up-alt2').addClass('dashicons-arrow-down-alt2');
            } else {
                $body.slideDown(150);
                $row.removeClass('section-collapsed');
                $btn.attr('aria-expanded', 'true');
                $icon.removeClass('dashicons-arrow-down-alt2').addClass('dashicons-arrow-up-alt2');
            }
        },

        sectionFieldTemplates: {
            banner: function (name) {
                return `
                    <p><label>Text</label><br>
                    <input type="text" class="large-text" name="${name}[text]" value="" /></p>
                    <p><label>Link URL</label><br>
                    <input type="url" class="large-text" name="${name}[link_url]" value="" /></p>
                    <p><label>Link Text</label><br>
                    <input type="text" name="${name}[link_text]" value="" /></p>
                    <p>
                        Background: <input type="color" name="${name}[bg_color]" value="#000000" />
                        &nbsp; Text: <input type="color" name="${name}[text_color]" value="#ffffff" />
                    </p>
                `;
            },
            newsletter_signup: function (name) {
                return `
                    <p><label>Title</label><br>
                    <input type="text" class="large-text" name="${name}[title]" value="" /></p>
                    <p><label>Subtitle</label><br>
                    <input type="text" class="large-text" name="${name}[subtitle]" value="" /></p>
                    <p><label>Button Text</label><br>
                    <input type="text" name="${name}[button_text]" value="Subscribe" /></p>
                `;
            },
            category_grid: function (name) {
                return `
                    <p><label>Title</label><br>
                    <input type="text" class="large-text" name="${name}[title]" value="" /></p>
                    <p><label>Categories</label><br>
                    <div class="custom-select-wrapper">
                        <select class="category-select" name="${name}[category_ids][]" multiple="multiple" style="width:100%;" data-placeholder="Type to search categories..."></select>
                    </div></p>
                `;
            },
            testimonials: function (name) {
                return `
                    <p><label>Title</label><br>
                    <input type="text" class="large-text" name="${name}[title]" value="" /></p>
                    <div class="testimonial-items"></div>
                    <button type="button" class="button add-testimonial-btn">+ Add Testimonial</button>
                `;
            }
        },

        sectionLabels: {
            banner: 'Promo Banner',
            newsletter_signup: 'Newsletter Signup',
            category_grid: 'Category Grid',
            testimonials: 'Testimonials'
        },

        addSection: function (type) {
            const template = this.sectionFieldTemplates[type];
            if (!template) return;

            const uid = 'sec_' + Math.random().toString(36).slice(2, 12);
            const label = this.sectionLabels[type] || type;
            // Index is a placeholder — reindexSections() immediately corrects it.
            const idx = '__NEW__';
            const name = `shop_builder_options[home][sections][${idx}][data]`;

            const $row = $(`
                <div class="section-row" data-type="${type}">
                    <input type="hidden" name="shop_builder_options[home][sections][${idx}][id]" value="${uid}" />
                    <input type="hidden" name="shop_builder_options[home][sections][${idx}][type]" value="${type}" />
                    <div class="section-row-header">
                        <span class="section-drag-handle dashicons dashicons-move" title="Drag to reorder"></span>
                        <button type="button" class="section-toggle-btn button-link" aria-expanded="true" title="Collapse/expand this section">
                            <span class="dashicons dashicons-arrow-up-alt2"></span>
                        </button>
                        <strong class="section-title">${label}</strong>
                        <label class="section-enabled-toggle">
                            <input type="checkbox" name="shop_builder_options[home][sections][${idx}][enabled]" value="1" checked />
                            Enabled
                        </label>
                        <button type="button" class="button button-link-delete remove-section-btn">Remove</button>
                    </div>
                    <div class="section-row-body">${template(name)}</div>
                </div>
            `);

            $('#sections-container').append($row);
            $row.find('.category-select').trigger('select2-init-category');
            this.reindexSections();
        },

        addTestimonialItem: function ($section) {
            const $items = $section.find('.testimonial-items');
            const dataAttrIndex = $section.data('index');
            const baseName = `shop_builder_options[home][sections][${dataAttrIndex}][data]`;
            const itemIndex = $items.children('.testimonial-item').length;

            // ✅ FIX: field renamed quote -> review_text (label updated too).
            const $item = $(`
                <div class="testimonial-item" style="border:1px solid #ddd; padding:10px; margin-bottom:8px;">
                    <input type="text" placeholder="Name and Title" name="${baseName}[items][${itemIndex}][name]" value="" />
                    <textarea placeholder="Review Text" rows="2" class="large-text" name="${baseName}[items][${itemIndex}][review_text]"></textarea>
                    <button type="button" class="button button-link-delete remove-testimonial-btn">Remove</button>
                </div>
            `);

            $items.append($item);
        },

        /**
         * Re-derive every input's `name` attribute from the current DOM order,
         * so PHP receives sections[0], sections[1], ... in the order the user
         * actually arranged them (drag/add/remove all call this).
         */
        reindexSections: function () {
            $('#sections-container > .section-row').each(function (sectionIndex) {
                const $row = $(this);
                $row.attr('data-index', sectionIndex);

                $row.find('[name]').each(function () {
                    const $field = $(this);
                    const newName = $field.attr('name').replace(
                        /sections\]\[(?:\d+|__NEW__)\]/,
                        `sections][${sectionIndex}]`
                    );
                    $field.attr('name', newName);
                });

                // Also fix up testimonial item indices within this row so they
                // stay 0,1,2... after an item is removed.
                $row.find('.testimonial-items > .testimonial-item').each(function (itemIndex) {
                    $(this).find('[name]').each(function () {
                        const $field = $(this);
                        const newName = $field.attr('name').replace(
                            /items\]\[\d+\]/,
                            `items][${itemIndex}]`
                        );
                        $field.attr('name', newName);
                    });
                });
            });
        },

        /* -------------------------
           Tab Switching & Iframe
           ------------------------- */
        bindEvents: function () {
            $(document).on('click', '.nav-tab', function (e) {
                e.preventDefault();
                const target = $(this).data('target');
                $('.nav-tab').removeClass('nav-tab-active');
                $(this).addClass('nav-tab-active');
                $('.tab-content').hide().removeClass('active');
                $('#' + target).show().addClass('active');
            });

            $('#update-preview').on('click', function () {
                const iframe = document.getElementById('shop-preview-frame');
                if (iframe) iframe.src = iframe.src;
            });
        },

        /* -------------------------
           Helpers
           ------------------------- */
        showStatus: function (html, color, autohide, selector) {
            const $status = $(selector || '#sync-status');
            $status.stop(true).show().html(html).css('color', color);
            if (autohide) {
                setTimeout(function () {
                    $status.fadeOut(400, function () { $status.show(); });
                }, autohide);
            }
        },

        /* -------------------------
           Save Draft (AJAX form submit)
           ------------------------- */
        bindFormSubmit: function () {
            let iframeRefreshTimer = null;

            $('.shop-builder-sidebar form').on('submit', function (e) {
                e.preventDefault();

                ShopBuilder.reindexSections();

                const $form = $(this);
                const $submitBtn = $form.find('input[type="submit"]');
                const $iframe = $('#shop-preview-frame');

                $submitBtn.prop('disabled', true).val('Saving...');
                ShopBuilder.showStatus('⏳ Saving draft...', '#666', false);

                const data = $form.serialize()
                    + '&action=save_shop_builder_draft'
                    + '&nonce=' + shopBuilder.nonce;

                $.post(ajaxurl, data, function (response) {
                    if (response.success) {
                        $submitBtn.prop('disabled', false).val('Save Draft');
                        ShopBuilder.showStatus('✅ Draft saved!', 'green', 3000);

                        clearTimeout(iframeRefreshTimer);
                        iframeRefreshTimer = setTimeout(function () {
                            const currentSrc = $iframe.attr('src');
                            $iframe.attr('src', currentSrc);
                        }, 500);
                    } else {
                        $submitBtn.prop('disabled', false).val('Save Draft');
                        ShopBuilder.showStatus('❌ Error: ' + (response.data || 'Unknown error'), 'red', 5000);
                    }
                }).fail(function () {
                    $submitBtn.prop('disabled', false).val('Save Draft');
                    ShopBuilder.showStatus('❌ Connection error. Please try again.', 'red', 5000);
                });
            });
        },

        /* -------------------------
           Push to GitHub / Live
           ------------------------- */
        bindGithubPush: function () {
            $('#push-to-github').on('click', function (e) {
                e.preventDefault();

                if (!confirm('Are you sure you want to push these settings to the LIVE website?')) return;

                const $btn = $(this);
                $btn.prop('disabled', true).text('Pushing...');
                ShopBuilder.showStatus('⏳ Syncing with GitHub...', '#666', false);

                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'push_to_github',
                        nonce: shopBuilder.nonce
                    },
                    success: function (response) {
                        if (response.success) {
                            ShopBuilder.showStatus('✅ Live website updated! ' + (response.data || ''), 'green', 5000);
                        } else {
                            ShopBuilder.showStatus('❌ Error: ' + (response.data || 'Unknown error'), 'red', 0);
                        }
                    },
                    error: function () {
                        ShopBuilder.showStatus('❌ Connection error. Please try again.', 'red', 0);
                    },
                    complete: function () {
                        $btn.prop('disabled', false).text('Push to Live Website');
                    }
                });
            });
        }
    };

    $(function () {
        ShopBuilder.init();
    });

})(jQuery);