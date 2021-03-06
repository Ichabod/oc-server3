{***************************************************************************
 *  You can find the license in the docs directory
 *
 *  Unicode Reminder メモ
 ***************************************************************************}
{t 1=$domain}welcome at %1!{/t}

{t}The following data has been stored for your account:{/t}

{t}Username{/t}: {$username}
{t}E-Mail address{/t}: {$to}
{if $first_name != ''}{t}First name{/t}: {$first_name}
{/if}
{if $last_name != ''}{t}Last name{/t}: {$last_name}
{/if}
{if $country != ''}{t}Country{/t}: {$country}
{/if}

{t}Before you can login for the first time, you have to activate your account by clicking on the following link:{/t}

{$short_activation_page}?e={$to|urlencode}&c={$code}

{t 1=$activation_page}Alternatively, you can visit %1 and enter the following code manually:{/t}

{$code}

{t}If you don't login in the next 14 days, your account will be deleted.{/t}

