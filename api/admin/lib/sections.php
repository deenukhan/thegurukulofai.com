<?php
/**
 * The dashboard's pages, in menu order. Settings is the only page that changes
 * setup; the others show data and offer two small actions (add-on sent, resend
 * email). To add a page: add one line here and one file in views/ named after
 * its key. The menu, the page title and the period picker all come from this list.
 *
 * group: the menu heading it sits under. range: true if the page has a period picker.
 * intro: the line under the page title.
 */
return [
    'overview' => ['label' => 'Overview',      'icon' => 'home',      'group' => 'Business', 'range' => true,
                   'intro' => 'How sales are going, and anything that needs you.'],
    'sales'    => ['label' => 'Sales',         'icon' => 'rupee',     'group' => 'Business', 'range' => true,
                   'intro' => 'Every payment, the money after fees, and payouts to your bank.'],
    'funnel'   => ['label' => 'Funnel',        'icon' => 'funnel',    'group' => 'Business', 'range' => true,
                   'intro' => 'From page visit to paid seat, and who stopped on the way.'],
    'sessions' => ['label' => 'Sessions',      'icon' => 'calendar',  'group' => 'Business', 'range' => false,
                   'intro' => 'Each Sunday class: seats, reminders and who opened their join link.'],
    'recordings' => ['label' => 'Recordings', 'icon' => 'video',     'group' => 'Business', 'range' => false,
                   'intro' => 'The recording link and passcode for each class, ready to share.'],
    'people'   => ['label' => 'People',        'icon' => 'users',     'group' => 'Business', 'range' => false,
                   'intro' => 'Everyone who filled the booking form, buyers and leads.'],
    'emails'   => ['label' => 'Messages',      'icon' => 'mail',      'group' => 'Messages', 'range' => false,
                   'intro' => 'Every email and WhatsApp message the system sends, and what happened to each one.'],
    'inbox'    => ['label' => 'Inbox',         'icon' => 'whatsapp',  'group' => 'WhatsApp', 'range' => false,
                   'intro' => 'Every WhatsApp conversation. Answer from the business number.'],
    'wa_templates' => ['label' => 'Templates', 'icon' => 'list', 'group' => 'WhatsApp', 'range' => false,
                   'intro' => 'Write WhatsApp templates, send them to Meta, and see which are approved.'],
    'ads'      => ['label' => 'Ads',           'icon' => 'megaphone', 'group' => 'Growth',   'range' => true,
                   'intro' => 'Which ads bring paying buyers, and what they cost.'],
    'coupons'  => ['label' => 'Coupons',       'icon' => 'tag',       'group' => 'Growth',   'range' => false,
                   'intro' => 'Make discount codes, see how often each is used, and stop them.'],
    'settings' => ['label' => 'Settings',      'icon' => 'sliders',   'group' => 'System',   'range' => false,
                   'intro' => 'The few things that change often.'],
    'health'   => ['label' => 'System health', 'icon' => 'pulse',     'group' => 'System',   'range' => false,
                   'intro' => 'Settings, automatic tasks and recent server errors.'],
    'activity' => ['label' => 'Activity log',  'icon' => 'list',      'group' => 'System',   'range' => false,
                   'intro' => 'Every sign-in, download and automatic run.'],
];
