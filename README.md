# Backend module for transferring content in Neos CMS multi sites

## Introduction

This plugin will add a new backend module for copying nodes between sites in a 
Neos multi site installation.

It's currently compatible with Neos 4.3 and the 5.* branch

## Example

![Preview](Documentation/preview.png) 

### Warning

This package was built to solve a very specific issue and should only be used by 
website administrators who know what their doing.

Future versions of this package might improve the usability and PRs to do this are very welcome.

Also note that references and links inside the copied nodes are not updated to link to their copied target 
and therefore still link to the site where they were copied from.
                
## Installation

Run this in your site package

    composer require --no-update shel/neos-transfercontent
    
Then run `composer update` in your project directory.

## How to use

 1. Open the "Transfer content" module located in the module menu
 2. In the first dropdown, choose what site you are copying from
 3. Enter the `identifier` of page you would like to copy. The identifier can be found in the inspector to your right, when you are editing a document node. Expand the "Additional info" box for the details
 4. Choose what site you are copying to
 5. Enter the `identifier` where you would like the content to be copied **into**. Please understand, that the tool take the page chosen above **including subpages** and copies **into** the identifier your enter. It doesn't override the entered page


## Contributions

Contributions are very welcome! 

Please create detailed issues and PRs.  

**If you use this package and want to support or speed up it's development, [get in touch with me](mailto:transfercontent@helzle.it).**

Or you can also support me directly via [patreon](https://www.patreon.com/shelzle).

## License

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
THE SOFTWARE.
