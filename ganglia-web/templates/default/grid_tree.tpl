<table border="0" width="100%">
<tr>
  <td class="title"><B>{$self} Grid Tree</b></td>
</tr>
</table>

<div align="center">
<table cellspacing="5" cellpadding="5" border="1">
<tr>

{if isset($parentgrid)}
<td align="center">
<table cellpadding="3" cellspacing="3" border="0">
{$parents}
</table>
</td>
{/if}
</tr>

<tr>
<td align="center">

<table cellpadding="3" cellspacing="3" border="0">
<tr>
 <td colspan="{$n}" class="self" align="center" style="border: thin solid rgb(47,47,47);">
  {$self}
 </td>
</tr>

<tr>
 {$children}
</tr>
</table>

</td>
</tr>

</table>
</div>

<p>
<hr />
<b>Legend:</b>
<table cellspacing="5" border="0">
<tr>
<td class="self" width="20">&nbsp;</td><td>This Grid.</td>
</tr>
<tr>
<td class="grid">&nbsp;</td><td>A Remote Grid.</td>
</tr>
</table>

