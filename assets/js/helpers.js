const spinner = $('<span class="spinner-border spinner-border-sm mr-3"></span>');
const toast = $('#toast');

function parseQuery(queryString) 
{
    var query = {};
    var pairs = (queryString[0] === '?' ? queryString.substr(1) : queryString).split('&');
    for (var i = 0; i < pairs.length; i++) {
        var pair = pairs[i].split('=');
        query[decodeURIComponent(pair[0])] = decodeURIComponent(pair[1] || '');
    }
    return query;
}

function parsePath(pathString) 
{
    var query = [];
    var pairs = (pathString[0] === '/' ? pathString.substr(1) : pathString).split('/');
    for (var i = 0; i < pairs.length; i++) {
        query[i] = decodeURIComponent(pairs[i]);
    }
    return query;
}

function jsonBeautify(text)
{
    try {
        return JSON.stringify(JSON.parse(cm.getValue(), parseReviver), stringifyReplacer, "\t");        
    } catch (error) {
        return text;
    }
}

function parseReviver(key, value) {
    if (/^\d+$/.test(value)) {
        var b = BigInt(value);
        console.log(b);
        return b;
    }
    return value;
}

function stringifyReplacer(key, value) {
    if (typeof value === 'bigint') {
        console.log(value, value.toString(), );
        return value.toString() + 'n';
    } else {
        return value;
    }
}

var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
  return new bootstrap.Tooltip(tooltipTriggerEl);
});

function setButtonBusy(btn)
{
    btn.attr('disabled', true).prepend(spinner).find('i').hide();
}

function setButtonReady(btn)
{
    btn.removeAttr('disabled').find(spinner).remove();
    btn.find('i').show();
}

function ajax(url, data, onSuccess, onError, onComplete) 
{
    return $.ajax({
        url: url,
        type: data ? 'POST' : 'GET',
        data: data,
        success: onSuccess,
        error: onError,
        complete: onComplete,
    })
}

function handleAjaxError(xhr, errorType, error)
{
    // Never echo raw responseText into the DOM: it can contain server-side
    // stack traces or reflected user input. Show a generic message; details
    // belong in the console.
    console.error('AJAX error', xhr.status, errorType, error);
    let message = 'Request failed';
    try {
        const parsed = JSON.parse(xhr.responseText);
        if (parsed && parsed.error) {
            message = parsed.error; // server-controlled, escaped below
        }
    } catch (e) {}
    showToast(message, 'bg-danger');
}

// Variant for trusted, hardcoded markup (icons). Never pass server/user data.
const showToastHtml = function(content, bgclass, timeout)
{
    if (!timeout) {
        timeout = 5000;
    }
    if (!bgclass) {
        bgclass = 'bg-primary';
    }
    toast.find('#toast-body').html(content);
    toast.addClass(bgclass).addClass('show');
    setTimeout(function () {
        toast.removeClass('show').removeClass(bgclass);
    }, timeout);
};

const toastTimeout = null;
const showToast = function(content, bgclass, timeout)
{
    if (!timeout) {
        timeout = 5000;
    }
    
    if (!bgclass) {
        bgclass = 'bg-primary';
    }
    
    // Treat content as plain text by default to avoid XSS via server /
    // user-influenced strings. Callers passing icons must use showToastHtml.
    toast.find('#toast-body').text(content);
    
    toast.addClass(bgclass).addClass('show');
    
    clearTimeout(toastTimeout);
    setTimeout(function () {
        toast.removeClass('show').removeClass(bgclass);
    }, timeout);
}
