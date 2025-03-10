<?php

use Egns_Core\Egns_Helper;

$all_destination = Egns_Helper::get_posts('destination');
$all_tours = array(
    'post_type'         => 'tours',
    'post_status'       => 'publish',
    'posts_per_page'    => -1,
);
$wp_query = new WP_Query($all_tours);

$tour_types = get_terms('tour-types', array(
    'orderby'    => 'count',
    'hide_empty' => 0,
));

?>

<form method="get" action="<?php echo get_post_type_archive_link('tours'); ?>" id="tour-query-form">
    <div class="filter-area">
        <div class="row g-xl-3 gy-3">
            <div class="col-xl-6 col-sm-6 d-flex justify-content-center divider">
                <div class="single-search-box">

                    <?php if (!empty($all_destination)) : ?>
                        <div class="searchbox-input">
                            <h3><?php echo esc_html__('Destination', 'triprex-core') ?></h3>
                            <div class="custom-select-dropdown">
                                <div class="select-input">
                                    <input type="text" name="des" readonly value="" placeholder="<?php echo esc_html__('Choose destination', 'triprex-core') ?>">
                                    <i class="bi bi-chevron-down"></i>
                                </div>
                                <div class="custom-select-wrap">
                                    <div class="custom-select-search-area">
                                        <i class='fas fa-search'></i>
                                        <input type="text" placeholder="<?php echo esc_attr__('Type Your Destination', 'triprex-core') ?>">
                                    </div>
                                    <ul class="option-list">
                                        <?php foreach ($all_destination as $destination) : ?>
                                            <?php
                                            $get_location = get_the_terms($destination->ID, 'city-location');
                                            $tourCount = \Egns_Core\Egns_Helper::get_tour_count_by_destination_id($destination->ID);
                                            ?>
                                            <li>
                                                <div class="destination">
                                                    <h6><?php echo $destination->post_title ?? '' ?></h6>
                                                    <?php
                                                    $names = array();
                                                    if (!empty($get_location) && !is_wp_error($get_location)) {
                                                        foreach ($get_location as $location) {
                                                            $names[] = $location->name;
                                                        }
                                                        echo sprintf('<p> %s </p>', implode(',', $names));
                                                    }
                                                    ?>
                                                </div>
                                                <div class="tour">
                                                    <span>
                                                        <?php
                                                        echo esc_html($tourCount) . '<br>' . esc_html(_n('Tour', 'Tours', $tourCount, 'triprex-core'));
                                                        ?>
                                                    </span>
                                                </div>
                                            </li>
                                        <?php endforeach ?>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    <?php endif ?>
                    <div class="icon">
                        <svg xmlns="http://www.w3.org/2000/svg" width="27" height="27" viewBox="0 0 27 27">
                            <path d="M18.0075 17.8392C20.8807 13.3308 20.5195 13.8933 20.6023 13.7757C21.6483 12.3003 22.2012 10.5639 22.2012 8.75391C22.2012 3.95402 18.3062 0 13.5 0C8.7095 0 4.79883 3.94622 4.79883 8.75391C4.79883 10.5627 5.3633 12.3446 6.44361 13.8399L8.99237 17.8393C6.26732 18.2581 1.63477 19.506 1.63477 22.2539C1.63477 23.2556 2.28857 24.6831 5.40327 25.7955C7.57814 26.5722 10.4536 27 13.5 27C19.1966 27 25.3652 25.3931 25.3652 22.2539C25.3652 19.5055 20.7381 18.2589 18.0075 17.8392ZM7.76508 12.9698C7.75639 12.9562 7.7473 12.9428 7.73782 12.9298C6.83886 11.6931 6.38086 10.2274 6.38086 8.75391C6.38086 4.79788 9.56633 1.58203 13.5 1.58203C17.4255 1.58203 20.6191 4.7993 20.6191 8.75391C20.6191 10.2297 20.1698 11.6457 19.3195 12.8498C19.2432 12.9503 19.6408 12.3327 13.5 21.9686L7.76508 12.9698ZM13.5 25.418C7.27766 25.418 3.2168 23.589 3.2168 22.2539C3.2168 21.3566 5.30339 19.8811 9.92714 19.306L12.8329 23.8656C12.9044 23.9777 13.0029 24.0701 13.1195 24.134C13.2361 24.198 13.367 24.2315 13.4999 24.2315C13.6329 24.2315 13.7638 24.198 13.8804 24.134C13.9969 24.0701 14.0955 23.9777 14.167 23.8656L17.0727 19.306C21.6966 19.8811 23.7832 21.3566 23.7832 22.2539C23.7832 23.5776 19.7589 25.418 13.5 25.418Z" />
                            <path d="M13.5 4.79883C11.3192 4.79883 9.54492 6.57308 9.54492 8.75391C9.54492 10.9347 11.3192 12.709 13.5 12.709C15.6808 12.709 17.4551 10.9347 17.4551 8.75391C17.4551 6.57308 15.6808 4.79883 13.5 4.79883ZM13.5 11.127C12.1915 11.127 11.127 10.0624 11.127 8.75391C11.127 7.44541 12.1915 6.38086 13.5 6.38086C14.8085 6.38086 15.873 7.44541 15.873 8.75391C15.873 10.0624 14.8085 11.127 13.5 11.127Z" />
                        </svg>
                    </div>
                </div>
            </div>
            <div class="col-xl-6 col-sm-6 d-flex justify-content-centerhome1-banner-bottom ">
                <div class="single-search-box">

                    <div class="searchbox-input">
                        <h3><?php echo esc_html__('Departure month', 'triprex-core') ?></h3>
                        <div class="custom-select-dropdown">
                            <div class="select-input">
                                <input type="text" readonly name="month" value="" placeholder="<?php echo esc_attr('Choose month') ?>">
                                <i class="bi bi-chevron-down"></i>
                            </div>
                            <div class="custom-select-wrap two">
                                <ul class="option-list">
                                    <?php foreach (Egns_Core\Egns_Helper::egns_all_get_month() as $key => $month) : ?>
                                        <li class="single-item">
                                            <h6><?php echo esc_html($month) ?></h6>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        </div>
                    </div>
                    <div class="icon">
                        <svg xmlns="http://www.w3.org/2000/svg" width="27" height="27" viewBox="0 0 27 27">
                            <g clip-path="url(#clip0_273_1743)">
                                <path d="M18.3111 15.8842L14.5465 13.0608V7.30946C14.5465 6.73117 14.0791 6.26373 13.5008 6.26373C12.9225 6.26373 12.4551 6.73117 12.4551 7.30946V13.5837C12.4551 13.9131 12.6099 14.2237 12.8734 14.4203L17.0562 17.5574C17.2367 17.6933 17.4566 17.7667 17.6825 17.7665C18.0015 17.7665 18.3152 17.6232 18.5202 17.3472C18.8674 16.886 18.7733 16.2303 18.3111 15.8842Z" />
                                <path d="M13.5 0C6.05565 0 0 6.05565 0 13.5C0 20.9444 6.05565 27 13.5 27C20.9444 27 27 20.9444 27 13.5C27 6.05565 20.9444 0 13.5 0ZM13.5 24.9086C7.21011 24.9086 2.09139 19.7899 2.09139 13.5C2.09139 7.21011 7.21011 2.09139 13.5 2.09139C19.7909 2.09139 24.9086 7.21011 24.9086 13.5C24.9086 19.7899 19.7899 24.9086 13.5 24.9086Z" />
                            </g>
                        </svg>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <button type="submit"><i class="fas fa-search"></i></button>
</form>