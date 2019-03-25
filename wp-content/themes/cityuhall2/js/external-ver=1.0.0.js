function x()
{
  if ( !document.getElementsByTagName)
  {
    return;
  }
  var a = document.getElementsByTagName('a');
  for (var i=0; i<a.length; i++)
  {
    if ( a[i].getAttribute('href') && a[i].getAttribute('rel') == 'external' )
    {
      a[i].target = '_blank';
    }
  }
}

window.onload = x;