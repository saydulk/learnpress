<?php
/**
 * Template list sections.
 *
 * @since 3.0.0
 */

learn_press_admin_view( 'course/section' );

?>
<script src="//cdnjs.cloudflare.com/ajax/libs/Sortable/1.6.0/Sortable.min.js"></script>
<script src="//cdnjs.cloudflare.com/ajax/libs/Vue.Draggable/2.14.1/vuedraggable.min.js"></script>

<script type="text/x-template" id="tmpl-lp-list-sections">
    <div class="curriculum-sections">
        <draggable :list="sections" :options="{handle: '.movable'}" @end="updateSortSections">
            <lp-section v-for="(section, index) in sections" :section="section" :index="index" :key="index" :order="index+1"></lp-section>
        </draggable>

        <div class="add-new-section">
            <button type="button" :class="loading ? 'updating-message' : ''" class="button button-primary" @click.prevent="addSection"><?php esc_html_e( 'Add new section', 'learnpress' ); ?></button>
        </div>
    </div>

</script>

<script>
    (function (Vue, $store) {

        Vue.component('lp-list-sections', {
            template: '#tmpl-lp-list-sections',
            data: function () {
                return {
                    loading: false
                };
            },
            created: function () {
                var vm = this;

                $store.subscribe(function (mutation, state) {
                    if (mutation.type !== 'ADD_NEW_SECTION') {
                        return;
                    }

                    vm.loading = false;
                });
            },
            methods: {
                addSection: function () {
                    this.loading = true;
                    $store.dispatch('addNewSection');
                },
                updateSortSections: function () {
                    var orders = [];
                    this.sections.forEach(function (section, index) {
                        orders.push(section.id);
                    });

                    $store.dispatch('updateSortSections', orders);
                }
            },
            computed: {
                sections: function () {
                    return $store.getters.sections;
                }
            }
        });
    })(Vue, LP_Curriculum_Store);
</script>