if( typeof modalWin == 'undefined' ){

var xxx_modalPopupWindow = null;

function CreateModalPopUpObject() {
    if (xxx_modalPopupWindow == null) {
        xxx_modalPopupWindow = new ModalPopupWindow();
    }
    return xxx_modalPopupWindow;
}

function ModalPopupWindow() {
    var strOverLayHTML = '<div id="divOverlay" style="position:absolute;z-index:10; background-color:WHITE; filter: alpha(opacity = 70);opacity:0.7;"></div><div id="divFrameParent" style="position:absolute;z-index:12; display:none;background-color:white;border:1px solid;-moz-box-shadow: 0 0 10px 10px #BBB;-webkit-box-shadow: 0 0 10px 10px #BBB;box-shadow: 0 0 10px 10px #BBB;padding:10px;line-height:21px;font-size:15px;color:#000;text-align:left;font-family:Arial,Helvetica,sans-serif;"	class="Example_F"><table style="margin:0px!important;border:0px!important;" width="100%" height="95%" border="0" cellpadding="0" cellspacing="0"><tr><td style="padding:0px!important;border:0px!important;"  align="left" colspan="2"><div class="checkout-heading" id="spanOverLayTitle"></div></td></tr><tr ><td  style="padding:0px!important;border:0px!important;"  colspan="2" width="100%" id="tdOverLay"><div id="divMessage" style="display:none;"><span id="spanMessage"></span></div><span id="spanLoading"> <img id="imgOverLayLoading" src="" alt="Loading..." /></span><iframe name="overlay_frame" id="overlay_frame" src="javascript://" frameborder="0" scrolling="auto" ></iframe> </td></tr></table></div>'
    var orginalHeight;
    var orginalWidth;
    var btnStyle="";
    var maximize = false;
	div = document.createElement("div");
	div.innerHTML = strOverLayHTML;
    document.body.appendChild(div);

    function Maximisze() {
        if (!maximize) {
            maximize = true;
            ResizePopUp(window.screen.availHeight - 200, window.screen.availWidth - 50);
        } else {
            maximize = false;
            ResizePopUp(orginalHeight, orginalWidth);
        }
    }

    function ResizePopUp(height, width) {
        var divFrameParent = document.getElementById("divFrameParent");
        var divOverlay = document.getElementById("divOverlay");
        var iframe = document.getElementById("overlay_frame");
        var tdOverLay = document.getElementById("tdOverLay");
        var left = (window.screen.availWidth - width) / 2;
        var top = (window.screen.availHeight - height) / 2;
        var xy = GetScroll();
        if (maximize) {
            left = xy[0] + 10;
            top = xy[1] + 10;
        } else {
            left += xy[0];
            top += xy[1];
        }
        divFrameParent.style.top = top + "px";
        divFrameParent.style.left = left + "px";
        divFrameParent.style.height = height + "px";
        divFrameParent.style.width = width + "px";
        iframe.style.height = divFrameParent.offsetHeight - 60 + "px";
        iframe.style.width = divFrameParent.offsetWidth - 2 + "px";
    }
    var onPopUpCloseCallBack = null;
    var callbackArray = null;

    this.SetButtonStyle = function (_btnStyle) {
      btnStyle =_btnStyle;
    }
    
    function ApplyBtnStyle(){
    }
    
    function __InitModalPopUp(height, width, title) {
        orginalWidth = width;
        orginalHeight = height;
        maximize = false;
        var divFrameParent = document.getElementById("divFrameParent");
        var divOverlay = document.getElementById("divOverlay");
        var iframe = document.getElementById("overlay_frame");
        var tdOverLay = document.getElementById("tdOverLay");
        var left = (window.screen.availWidth - width) / 2;
        var top = (window.screen.availHeight - height) / 2;
        var xy = GetScroll();
        left += xy[0];
        top += xy[1];
        document.getElementById("spanOverLayTitle").innerHTML = title;
        divOverlay.style.top = "0px";
        divOverlay.style.left = "0px";
        var e = document;
        var c = "Height";
        var maxHeight = Math.max(e.documentElement["client" + c], e.body["scroll" + c], e.documentElement["scroll" + c], e.body["offset" + c], e.documentElement["offset" + c]);
        c = "Width";
        var maxWidth = Math.max(e.documentElement["client" + c], e.body["scroll" + c], e.documentElement["scroll" + c], e.body["offset" + c], e.documentElement["offset" + c]);
        divOverlay.style.height = maxHeight + "px";
        divOverlay.style.width = maxWidth - 2 + "px";
        divOverlay.style.display = "";
        iframe.style.display = "none";
        divFrameParent.style.display = "";
        //$('#divFrameParent').animate({ opacity: 1 }, 2000);
        divFrameParent.style.top = (top-100) + "px";
        divFrameParent.style.left = left + "px";
        divFrameParent.style.height = height + "px";
        divFrameParent.style.width = width + "px";
        iframe.style.height = "0px";
        iframe.style.width = "0px";
        onPopUpCloseCallBack = null;
        callbackArray = null;
    }
    this.ShowMessage = function (message, height, width, title) {
        __InitModalPopUp(height, width, title);
        var tdOverLay = document.getElementById("tdOverLay");
        tdOverLay.style.height = "50px";
        tdOverLay.style.width = "0px";
        document.getElementById("spanMessage").innerHTML = message;
        document.getElementById("divMessage").style.display = "";
        document.getElementById("spanLoading").style.display = "none";
        ApplyBtnStyle();
		ShowDivInCenter("divFrameParent");
    }
    this.ShowConfirmationMessage = function (message, height, width, title, onCloseCallBack, firstButtonText, onFirstButtonClick, secondButtonText, onSecondButtonClick) {
        this.ShowMessage(message, height, width, title);
        var tdOverLay = document.getElementById("tdOverLay");
        var maxWidth = 100;
        document.getElementById("spanMessage").innerHTML = message;
        document.getElementById("divMessage").style.display = "";
        document.getElementById("spanLoading").style.display = "none";
        if (onCloseCallBack != null && onCloseCallBack != '') {
            onPopUpCloseCallBack = onCloseCallBack;
        }
        ApplyBtnStyle();
    }
    function ShowLoading() {
        document.getElementById("overlay_frame").style.display = "none";
        document.getElementById("spanLoading").style.display = "";
    }
    this.HideModalPopUp = function () {
        var divFrameParent = document.getElementById("divFrameParent");
        var divOverlay = document.getElementById("divOverlay");
        divOverlay.style.display = "none";
        divFrameParent.style.display = "none";
        if (onPopUpCloseCallBack != null && onPopUpCloseCallBack != '') {
            onPopUpCloseCallBack();
        }
    }
    this.CallCallingWindowFunction = function (index, para) {
        callbackArray[index](para);
    }
    this.ChangeModalPopUpTitle = function (title) {
        document.getElementById("spanOverLayTitle").innerHTML = title;
    }

    function setParentVariable(variableName, variableValue) {
        window[String(variableName)] = variableValue;
    }

    function GetScroll() {
        if (window.pageYOffset != undefined) {
            return [pageXOffset, pageYOffset];
        } else {
            var sx, sy, d = document,
                r = d.documentElement,
                b = d.body;
            sx = r.scrollLeft || b.scrollLeft || 0;
            sy = r.scrollTop || b.scrollTop || 0;
            return [sx, sy];
        }
    }
}


function AddEvent(html_element, event_name, event_function) 
{       
   if(html_element.attachEvent) //Internet Explorer
      html_element.attachEvent("on" + event_name, function() {event_function.call(html_element);}); 
   else if(html_element.addEventListener) //Firefox & company
      html_element.addEventListener(event_name, event_function, false); //don't need the 'call' trick because in FF everything already works in the right way          
} 
var modalWin = new CreateModalPopUpObject();
function closefunc(obj){
	modalWin.HideModalPopUp();
}
function ShowDivInCenter(divId)
{
    try
    {
		var div = document.getElementById(divId);
		divWidth = document.getElementById("divFrameParent").offsetWidth;
        divHeight = document.getElementById("divFrameParent").offsetHeight;

        // Get the x and y coordinates of the center in output browser's window 
        var centerX, centerY;
        if (self.innerHeight)
        {
            centerX = self.innerWidth;
            centerY = self.innerHeight;
        }
        else if (document.documentElement && document.documentElement.clientHeight)
        {
            centerX = document.documentElement.clientWidth;
            centerY = document.documentElement.clientHeight;
        }
        else if (document.body)
        {
            centerX = document.body.clientWidth;
            centerY = document.body.clientHeight;
        }
 
        var offsetLeft = (centerX - divWidth) / 2;
        var offsetTop = (centerY - divHeight) / 2;
 
        // The initial width and height of the div can be set in the
        // style sheet with display:none; divid is passed as an argument to // the function
        var ojbDiv = document.getElementById(divId);
         
        left = (offsetLeft) / 2 + window.scrollX;
        top = (offsetTop) / 2 + window.scrollY;

        ojbDiv.style.position = 'absolute';
        ojbDiv.style.top = top + 'px';
        ojbDiv.style.left = offsetLeft + 'px';
        ojbDiv.style.display = "block";

    }
    catch (e) {}
}

function Action1(){
alert('Action1 is excuted');
modalWin.HideModalPopUp();
}

function Action2(){
alert('Action2 is excuted');
modalWin.HideModalPopUp();
}

function EnrollNow(msg){
modalWin.HideModalPopUp();
modalWin.ShowMessage(msg,200,400,'User Information',null,null);
}

function EnrollLater(){
modalWin.HideModalPopUp();
modalWin.ShowMessage(msg,200,400,'User Information',null,null);
}

}
